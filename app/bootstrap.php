<?php
/**
 * Loads configuration, libraries and request handlers.
 */
declare(strict_types=1);

define('GM_ROOT', dirname(__DIR__));
define('GM_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'VCL Gamify needs PHP 8.0 or newer. On Hostinger: hPanel → Advanced → PHP Configuration.';
    exit;
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

require GM_ROOT . '/app/lib/helpers.php';
require GM_ROOT . '/app/lib/db.php';
require GM_ROOT . '/app/lib/auth.php';
require GM_ROOT . '/app/lib/participants.php';
require GM_ROOT . '/app/lib/personas.php';
require GM_ROOT . '/app/lib/activities.php';
require GM_ROOT . '/app/lib/uploads.php';
require GM_ROOT . '/app/handlers/pages.php';
require GM_ROOT . '/app/handlers/api.php';
require GM_ROOT . '/app/handlers/admin.php';
require GM_ROOT . '/app/handlers/setup.php';
require GM_ROOT . '/app/routes.php';

$GLOBALS['GM_CONFIG'] = load_config();

$debug = (bool) config('debug', false);
error_reporting(E_ALL);
if (function_exists('ini_set')) {
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');
    if (is_dir(GM_ROOT . '/storage') && is_writable(GM_ROOT . '/storage')) {
        ini_set('error_log', GM_ROOT . '/storage/error.log');
    }
}

set_exception_handler('gm_handle_exception');
