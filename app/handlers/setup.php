<?php
/**
 * First-run setup wizard: asks for the database and admin details, creates
 * the tables and writes config.php. Disabled as soon as config.php exists.
 */
declare(strict_types=1);

function setup_page(): void
{
    send_security_headers();
    if (is_configured()) {
        render_error(404, 'Already set up', 'This site is already configured. To change settings, edit config.php on your server.');
        return;
    }

    $v = [
        'app_name' => 'Gamify',
        'db_host' => 'localhost',
        'db_port' => '3306',
        'db_name' => '',
        'db_user' => '',
        'admin_user' => 'admin',
    ];
    $errors = [];
    $manualConfig = null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach ($v as $key => $_) {
            $v[$key] = clean_text($_POST[$key] ?? '', 120);
        }
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $adminPass = (string) ($_POST['admin_pass'] ?? '');

        if ($v['db_name'] === '' || $v['db_user'] === '') {
            $errors[] = 'Enter the database name and user from hPanel → Databases.';
        }
        if ($v['admin_user'] === '') {
            $errors[] = 'Choose an admin username.';
        }
        if (mb_strlen($adminPass) < 8) {
            $errors[] = 'Choose an admin password with at least 8 characters.';
        }

        $db = [
            'host' => $v['db_host'] !== '' ? $v['db_host'] : 'localhost',
            'port' => (int) ($v['db_port'] ?: 3306),
            'name' => $v['db_name'],
            'user' => $v['db_user'],
            'pass' => $dbPass,
        ];

        if (!$errors) {
            try {
                db_migrate(db_connect($db));
            } catch (PDOException $e) {
                $errors[] = 'Couldn’t connect to the database: ' . $e->getMessage();
            }
        }

        if (!$errors) {
            $config = [
                'app_name' => $v['app_name'] !== '' ? $v['app_name'] : 'Gamify',
                'db' => $db,
                'admin' => [
                    'username' => $v['admin_user'],
                    'password' => password_hash($adminPass, PASSWORD_DEFAULT),
                ],
                'secret' => random_token(32),
                'base_url' => '',
                'debug' => false,
            ];
            $php = "<?php\n// Created by the Gamify setup wizard on " . gmdate('Y-m-d H:i') . " UTC.\n"
                . "// You can edit these values at any time. See config.example.php for notes.\n"
                . 'return ' . var_export($config, true) . ";\n";

            if (@file_put_contents(GM_ROOT . '/config.php', $php, LOCK_EX) !== false) {
                @file_put_contents(GM_ROOT . '/storage/schema_version', (string) GM_SCHEMA_VERSION);
                $GLOBALS['GM_CONFIG'] = $config;
                admin_login();
                redirect('/admin?welcome=1');
            }
            $manualConfig = $php;
        }
    }

    render('setup', [
        'title' => 'Set up ' . ($v['app_name'] ?: 'Gamify'),
        'bodyClass' => 'page-setup',
        'v' => $v,
        'errors' => $errors,
        'manualConfig' => $manualConfig,
    ]);
}
