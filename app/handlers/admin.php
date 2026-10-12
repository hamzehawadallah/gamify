<?php
/**
 * Admin pages and API: sign in, manage activities, run them live.
 */
declare(strict_types=1);

function admin_find(int $id): array
{
    $activity = activity_by_id($id);
    if (!$activity) {
        if (is_api_request()) {
            json_error('This activity no longer exists.', 404, 'not_found');
        }
        render_error(404, 'Activity not found', 'It may have been deleted.');
        exit;
    }
    return $activity;
}

function admin_render(string $view, array $vars): void
{
    send_security_headers();
    $vars['bodyClass'] = trim('page-admin ' . ($vars['bodyClass'] ?? ''));
    $vars['csrf'] = csrf_token();
    render($view, $vars);
}

/* ---------- Sign in ---------- */

function admin_login_page(): void
{
    if (admin_user() !== null) {
        redirect('/admin');
    }
    send_security_headers();
    render('admin/login', ['title' => 'Sign in', 'bodyClass' => 'page-login', 'error' => null, 'username' => '']);
}

function admin_login_submit(): void
{
    send_security_headers();
    $username = clean_text($_POST['username'] ?? '', 100);
    $password = (string) ($_POST['password'] ?? '');
    $error = null;

    $lock = login_attempt_lock_minutes();
    if ($lock > 0) {
        $error = 'Too many attempts. Try again in ' . $lock . ' minutes.';
    } elseif (!admin_check_password($username, $password)) {
        usleep(300000);
        $error = 'That username and password don’t match.';
    }
    if ($error !== null) {
        http_response_code(401);
        render('admin/login', ['title' => 'Sign in', 'bodyClass' => 'page-login', 'error' => $error, 'username' => $username]);
        return;
    }
    login_clear_failures();
    admin_login();
    redirect('/admin');
}

function admin_logout_action(): void
{
    if (admin_user() !== null && hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        admin_logout();
    }
    redirect('/admin/login');
}

/* ---------- Pages ---------- */

function admin_dashboard(): void
{
    require_admin_page();
    admin_render('admin/dashboard', [
        'title' => 'Activities',
        'activities' => activity_list(),
        'scripts' => ['js/admin.js'],
    ]);
}

function admin_new(): void
{
    require_admin_page();
    admin_render('admin/editor', [
        'title' => 'New poll',
        'bodyClass' => 'page-editor',
        'editor' => null,
        'scripts' => ['js/poll.js', 'js/admin.js', 'js/editor.js'],
    ]);
}

function admin_edit(string $id): void
{
    require_admin_page();
    $activity = admin_find((int) $id);
    admin_render('admin/editor', [
        'title' => 'Edit poll',
        'bodyClass' => 'page-editor',
        'editor' => activity_editor_data($activity),
        'scripts' => ['js/poll.js', 'js/admin.js', 'js/editor.js'],
    ]);
}

function admin_activity_page(string $id): void
{
    require_admin_page();
    $activity = admin_find((int) $id);
    admin_render('admin/activity', [
        'title' => $activity['title'],
        'bodyClass' => 'page-activity',
        'data' => [
            'id' => (int) $activity['id'],
            'present_url' => activity_present_url($activity),
            'live' => activity_live_payload($activity, true),
        ],
        'scripts' => ['vendor/qrcode.js', 'js/results.js', 'js/admin.js', 'js/activity.js'],
    ]);
}

function admin_export_csv(string $id): void
{
    require_admin_page();
    $activity = admin_find((int) $id);
    $options = activity_options((int) $activity['id']);
    $labels = [];
    foreach ($options as $i => $o) {
        $labels[(int) $o['id']] = $o['label'] !== '' ? $o['label'] : 'Option ' . ($i + 1);
    }
    $safe = static function (string $v): string {
        // Stop spreadsheet apps from treating text as a formula.
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    };

    no_cache();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="poll-' . $activity['code'] . '-results.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic and accents correctly
    csv_row($out, [$safe($activity['title'])]);
    csv_row($out, []);
    csv_row($out, ['Name', 'Answer', 'Submitted (UTC)']);
    foreach (activity_response_rows((int) $activity['id'], $options) as $row) {
        $answers = array_map(static fn($oid) => $labels[$oid] ?? '', $row['option_ids']);
        csv_row($out, [$safe($row['name']), $safe(implode('; ', $answers)), rtrim($row['at'], 'Z')]);
    }
    $results = activity_results($activity, $options);
    csv_row($out, []);
    csv_row($out, ['Option', 'Votes', '% of voters']);
    foreach ($results['counts'] as $c) {
        $pct = $results['voters'] > 0 ? round($c['count'] / $results['voters'] * 100) . '%' : '0%';
        csv_row($out, [$safe($labels[$c['id']] ?? ''), $c['count'], $pct]);
    }
    csv_row($out, ['Total voters', $results['voters']]);
    fclose($out);
    exit;
}

function csv_row($handle, array $fields): void
{
    fputcsv($handle, $fields, ',', '"', '');
}

/* ---------- JSON API ---------- */

function admin_api_list(): void
{
    require_admin_api(false);
    json_out(['activities' => activity_list()]);
}

function admin_api_create(): void
{
    require_admin_api();
    $id = activity_save(null, input_json());
    json_out(['id' => $id, 'url' => url('admin/activity/' . $id)]);
}

function admin_api_update(string $id): void
{
    require_admin_api();
    admin_find((int) $id);
    activity_save((int) $id, input_json());
    json_out(['id' => (int) $id, 'url' => url('admin/activity/' . $id)]);
}

function admin_api_state(string $id): void
{
    require_admin_api();
    activity_set_state(admin_find((int) $id), input_json());
    json_out(activity_live_payload(admin_find((int) $id), true));
}

function admin_api_reset(string $id): void
{
    require_admin_api();
    activity_reset(admin_find((int) $id));
    json_out(activity_live_payload(admin_find((int) $id), true));
}

function admin_api_duplicate(string $id): void
{
    require_admin_api();
    $newId = activity_duplicate(admin_find((int) $id));
    json_out(['id' => $newId, 'url' => url('admin/activity/' . $newId)]);
}

function admin_api_delete(string $id): void
{
    require_admin_api();
    activity_delete(admin_find((int) $id));
    json_out(['ok' => true]);
}

/** Live results for the admin's own screen (includes names and hidden results). */
function admin_api_live(string $id): void
{
    require_admin_api(false);
    $activity = admin_find((int) $id);
    $since = (int) ($_GET['v'] ?? 0);
    if ($since > 0 && $since === (int) $activity['version']) {
        $activity = activity_wait_for_change((int) $activity['id'], $since);
        if (!$activity) {
            json_error('This activity was deleted.', 404, 'not_found');
        }
        if ((int) $activity['version'] === $since) {
            json_out(['changed' => false, 'version' => $since]);
        }
    }
    json_out(activity_live_payload($activity, true));
}

function admin_api_upload(): void
{
    require_admin_api();
    if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        json_error('That image is larger than your server allows. Try a smaller one.', 413, 'too_large');
    }
    $name = upload_store_image($_FILES['image'] ?? []);
    json_out(['image' => $name, 'url' => image_url($name)]);
}
