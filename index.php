<?php
/**
 * VCL Gamify — front controller. Every page and API request comes through here.
 */
declare(strict_types=1);

// Let PHP's built-in dev server (php -S) serve static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (preg_match('#^/(assets|uploads)/#', $path) && is_file(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

gm_run();
