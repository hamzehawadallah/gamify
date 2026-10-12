<?php
/**
 * Small helpers shared by every part of the app.
 */
declare(strict_types=1);

/** Thrown for problems the user can fix (bad input, closed poll, …). */
class UserError extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(string $message, int $status = 422, string $errorCode = 'invalid')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

function load_config(): ?array
{
    $file = GM_ROOT . '/config.php';
    if (!is_file($file)) {
        return null;
    }
    $cfg = require $file;
    return is_array($cfg) ? $cfg : null;
}

function is_configured(): bool
{
    return is_array($GLOBALS['GM_CONFIG'] ?? null);
}

/** config.php exists (even if it is broken). The setup wizard never runs then. */
function config_file_exists(): bool
{
    return is_file(GM_ROOT . '/config.php');
}

/** Read a config value using dot notation, e.g. config('db.host'). */
function config(string $key, $default = null)
{
    $node = $GLOBALS['GM_CONFIG'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

function app_name(): string
{
    $name = trim((string) config('app_name', 'Gamify'));
    return $name !== '' ? $name : 'Gamify';
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** The folder the app is installed in, e.g. "" for the domain root or "/vote". */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (substr($script, -10) === '/index.php') {
            $dir = dirname($script);
        } else {
            // Some servers (e.g. PHP's built-in one, for URLs ending in .xml or .csv)
            // report the requested path instead: use the app folder's place in the web root.
            $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
            $appRoot = realpath(GM_ROOT) ?: GM_ROOT;
            $dir = ($docRoot !== '' && strpos($appRoot . '/', rtrim($docRoot, '/') . '/') === 0)
                ? substr($appRoot, strlen(rtrim($docRoot, '/')))
                : '';
        }
        $dir = str_replace('\\', '/', $dir);
        $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
    }
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** Full URL including scheme and host — used for QR codes and share links. */
function absolute_url(string $path = ''): string
{
    $configured = rtrim((string) config('base_url', ''), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . url($path);
}

/** Path of the current request relative to the install folder, always starting with "/". */
function request_path(): string
{
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $base = base_path();
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    if (strpos($path, '/index.php') === 0) {
        $path = substr($path, strlen('/index.php'));
    }
    $path = '/' . ltrim($path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

/** URL of a file in /assets with a cache-busting version. */
function asset(string $path): string
{
    $file = GM_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : GM_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path, int $status = 302): void
{
    header('Location: ' . url($path), true, $status);
    exit;
}

function no_cache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // LiteSpeed (Hostinger) page cache
    header('X-LiteSpeed-Cache-Control: no-cache');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    no_cache();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, string $code = 'error'): void
{
    json_out(['error' => $message, 'code' => $code], $status);
}

/** JSON safe to place inside a <script type="application/json"> tag. */
function json_for_html($data): string
{
    return json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

function input_json(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
    }
    return $data;
}

function random_token(int $bytes = 20): string
{
    return bin2hex(random_bytes($bytes));
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** MySQL "Y-m-d H:i:s" (UTC) → ISO 8601, which every browser can parse. */
function iso_utc(string $datetime): string
{
    return str_replace(' ', 'T', $datetime) . 'Z';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Trim, collapse whitespace control characters and cap the length. */
function clean_text($value, int $max): string
{
    $value = is_scalar($value) ? (string) $value : '';
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }
    return $value;
}

function is_api_request(): bool
{
    $path = request_path();
    return strpos($path, '/api/') === 0 || strpos($path, '/admin/api/') === 0;
}

/** Render a view inside the shared layout. Views may set $title, $bodyClass, $scripts, $styles. */
function render(string $view, array $vars = [], ?string $layout = 'layout'): void
{
    no_cache();
    header('Content-Type: text/html; charset=utf-8');
    extract($vars, EXTR_SKIP);
    ob_start();
    require GM_ROOT . '/app/views/' . $view . '.php';
    $content = ob_get_clean();
    if ($layout === null) {
        echo $content;
        return;
    }
    require GM_ROOT . '/app/views/' . $layout . '.php';
}

function render_error(int $status, string $heading, string $message): void
{
    http_response_code($status);
    render('error', ['heading' => $heading, 'message' => $message, 'title' => $heading]);
}

function send_security_headers(bool $allowFraming = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($allowFraming) {
        // The presenter view is meant to be embedded (the PowerPoint add-in, Teams, Keynote …)
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: frame-ancestors *');
    } else {
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
    }
}

function gm_handle_exception(Throwable $e): void
{
    // A row changed underneath us (e.g. an option deleted mid-vote): ask to refresh.
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        $e = new UserError('This activity just changed. Refresh and try again.', 409, 'stale');
    }
    if (!$e instanceof UserError) {
        error_log('[gamify] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    $debug = (bool) config('debug', false);

    if ($e instanceof UserError) {
        if (is_api_request()) {
            json_error($e->getMessage(), $e->status, $e->errorCode);
        }
        render_error($e->status, 'Something’s not right', $e->getMessage());
        return;
    }

    $isDb = $e instanceof PDOException;
    $message = $isDb
        ? 'We couldn’t reach the database. Check the database settings in config.php.'
        : 'Something went wrong on our side. Please try again.';
    if ($debug) {
        $message .= ' (' . $e->getMessage() . ')';
    }
    if (is_api_request()) {
        json_error($message, 500, $isDb ? 'database' : 'server');
    }
    render_error(500, $isDb ? 'Database unavailable' : 'Unexpected error', $message);
}

/** Inline SVG icons (24×24 line icons). Shared with JavaScript through window.GM_ICONS. */
function icon_paths(): array
{
    return [
        'check' => '<path d="M5 12.5l4.5 4.5L19 7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'chevron-left' => '<path d="M15 5l-7 7 7 7"/>',
        'chevron-right' => '<path d="M9 5l7 7-7 7"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c1.9.7 3.1 2.4 3.5 5.2"/>',
        'person' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4.5 4.2-7 8-7s7 2.5 8 7"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4.5 6h.01M4.5 12h.01M4.5 18h.01" stroke-width="3"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'grip' => '<path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01" stroke-width="3.2"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/>',
        'eye' => '<path d="M2.5 12C3.5 9.2 7 5 12 5s8.5 4.2 9.5 7c-1 2.8-4.5 7-9.5 7s-8.5-4.2-9.5-7z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.1A10.6 10.6 0 0 1 12 5c5 0 8.5 4.2 9.5 7-.4 1.1-1.2 2.5-2.4 3.8M6.6 6.6C4.6 7.9 3.2 9.9 2.5 12c1 2.8 4.5 7 9.5 7 1.9 0 3.6-.6 5-1.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'present' => '<rect x="2.5" y="4" width="19" height="13" rx="2.5"/><path d="M8 21h8M12 17v4"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="3"/><path d="M16 8V6a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v7a3 3 0 0 0 3 3h2"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
        'edit' => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'more' => '<path d="M5 12h.01M12 12h.01M19 12h.01" stroke-width="3.4"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
        'reset' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM20.5 14v.01M14 20.5h.01M17 17h3.5v3.5H17z"/>',
        'poll' => '<path d="M6 20v-8M12 20V5M18 20v-5" stroke-width="2.6"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.8v.01"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.4v.01"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 6h3v2a3 3 0 0 1-3 3M7 6H4v2a3 3 0 0 0 3 3"/>',
        'link' => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'hand' => '<path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V12M11 11.5v-8a1.5 1.5 0 0 1 3 0V12M14 11.5V5.5a1.5 1.5 0 0 1 3 0V14M17 9.5a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-1.5a6 6 0 0 1-4.6-2.2L4.4 14.6a1.5 1.5 0 0 1 2.3-1.9L8 14"/>',
    ];
}

function icon(string $name, string $class = ''): string
{
    $paths = icon_paths()[$name] ?? '';
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    return '<svg' . $cls . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

function brand_mark(string $class = 'brand-mark'): string
{
    return '<img class="' . e($class) . '" src="' . e(asset('img/icon.svg')) . '" alt="" width="28" height="28">';
}
