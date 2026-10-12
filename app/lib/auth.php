<?php
/**
 * Admin authentication.
 *
 * Uses a signed cookie instead of PHP sessions: nothing to garbage-collect on
 * shared hosting, no session locks blocking the live-results connection, and
 * changing the admin password in config.php signs everyone out.
 */
declare(strict_types=1);

const GM_ADMIN_COOKIE = 'gm_admin';
const GM_ADMIN_TTL = 2592000; // 30 days
const GM_LOGIN_MAX_ATTEMPTS = 6;
const GM_LOGIN_LOCK_MINUTES = 15;

function app_secret(): string
{
    $secret = (string) config('secret', '');
    if (strlen($secret) < 16 || strpos($secret, 'replace-me') === 0) {
        // Fallback for hand-written configs without a proper secret.
        $secret = hash('sha256', json_encode([config('db'), config('admin.password'), GM_ROOT]));
    }
    return $secret;
}

/** The signing key also depends on the admin password, so changing it logs everyone out. */
function admin_signing_key(): string
{
    return hash_hmac('sha256', 'admin|' . (string) config('admin.password', ''), app_secret());
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

function cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => base_path() === '' ? '/' : base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function admin_check_password(string $username, string $password): bool
{
    $expectedUser = (string) config('admin.username', 'admin');
    $stored = (string) config('admin.password', '');
    if ($stored === '' || !hash_equals(mb_strtolower($expectedUser), mb_strtolower(trim($username)))) {
        // Still spend time hashing so timing doesn't reveal the username.
        password_verify($password, '$2y$10$usesomesillystringforsaltuJ0d5yqUZ3cCVq3Wf5f4vKpZKW2a');
        return false;
    }
    $info = password_get_info($stored);
    if (!empty($info['algo'])) {
        return password_verify($password, $stored);
    }
    return hash_equals($stored, $password);
}

function admin_login(): void
{
    $expires = time() + GM_ADMIN_TTL;
    $payload = implode('|', [(string) config('admin.username', 'admin'), (string) $expires, random_token(8)]);
    $sig = hash_hmac('sha256', $payload, admin_signing_key());
    $value = base64url_encode($payload) . '.' . $sig;
    setcookie(GM_ADMIN_COOKIE, $value, cookie_options($expires));
    $_COOKIE[GM_ADMIN_COOKIE] = $value;
}

function admin_logout(): void
{
    setcookie(GM_ADMIN_COOKIE, '', cookie_options(time() - 3600));
    unset($_COOKIE[GM_ADMIN_COOKIE]);
}

function admin_user(): ?string
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $raw = (string) ($_COOKIE[GM_ADMIN_COOKIE] ?? '');
    if ($raw === '' || strpos($raw, '.') === false) {
        return null;
    }
    [$encoded, $sig] = explode('.', $raw, 2);
    $payload = base64url_decode($encoded);
    if (!hash_equals(hash_hmac('sha256', $payload, admin_signing_key()), $sig)) {
        return null;
    }
    $parts = explode('|', $payload);
    if (count($parts) !== 3 || (int) $parts[1] < time()) {
        return null;
    }
    if (!hash_equals((string) config('admin.username', 'admin'), $parts[0])) {
        return null;
    }
    $user = $parts[0];
    return $user;
}

function csrf_token(): string
{
    return hash_hmac('sha256', 'csrf|' . (string) ($_COOKIE[GM_ADMIN_COOKIE] ?? ''), app_secret());
}

/** Gate for admin pages: redirects to the login page. */
function require_admin_page(): void
{
    if (admin_user() === null) {
        redirect('/admin/login');
    }
}

/** Gate for admin API calls: checks login and CSRF token. */
function require_admin_api(bool $checkCsrf = true): void
{
    if (admin_user() === null) {
        json_error('Your session has ended. Please sign in again.', 401, 'auth');
    }
    if ($checkCsrf) {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
        if (!hash_equals(csrf_token(), $sent)) {
            json_error('This page is out of date. Refresh and try again.', 419, 'csrf');
        }
    }
}

/** Returns minutes remaining on a lockout, or 0 when the IP may try again. */
function login_lock_minutes(): int
{
    $row = q_one('SELECT attempts, last_at FROM gm_login_attempts WHERE ip = ?', [client_ip()]);
    if (!$row || (int) $row['attempts'] < GM_LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    $unlock = strtotime($row['last_at'] . ' UTC') + GM_LOGIN_LOCK_MINUTES * 60;
    $left = $unlock - time();
    if ($left <= 0) {
        q('DELETE FROM gm_login_attempts WHERE ip = ?', [client_ip()]);
        return 0;
    }
    return (int) ceil($left / 60);
}

function login_record_failure(): void
{
    q(
        'INSERT INTO gm_login_attempts (ip, attempts, last_at) VALUES (?, 1, ?)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_at = VALUES(last_at)',
        [client_ip(), now_utc()]
    );
}

function login_clear_failures(): void
{
    q('DELETE FROM gm_login_attempts WHERE ip = ?', [client_ip()]);
}
