<?php
/**
 * URL map and request dispatcher.
 */
declare(strict_types=1);

function gm_routes(): array
{
    $code = '(\d{6})';
    $id = '(\d+)';
    return [
        // Participants
        ['GET', '#^/$#', 'page_home'],
        ['GET', "#^/$code$#", 'page_participant'],
        ['GET', '#^/api/me$#', 'api_me'],
        ['POST', '#^/api/me$#', 'api_me_save'],
        ['POST', '#^/api/me/forget$#', 'api_me_forget'],
        ['POST', '#^/api/personas$#', 'api_personas'],
        ['GET', "#^/api/activity/$code$#", 'api_activity'],
        ['POST', "#^/api/activity/$code/vote$#", 'api_vote'],

        // Presenter screen (embeddable in PowerPoint)
        ['GET', "#^/present/$code$#", 'page_present'],
        ['GET', "#^/api/present/$code$#", 'api_present'],

        // Free PowerPoint add-in: shows a presenter link live on a slide
        ['GET', '#^/powerpoint$#', 'page_powerpoint'],
        ['GET', '#^/powerpoint/manifest\.xml$#', 'page_powerpoint_manifest'],

        // Admin
        ['GET', '#^/admin$#', 'admin_dashboard'],
        ['GET', '#^/admin/login$#', 'admin_login_page'],
        ['POST', '#^/admin/login$#', 'admin_login_submit'],
        ['POST', '#^/admin/logout$#', 'admin_logout_action'],
        ['GET', '#^/admin/new$#', 'admin_new'],
        ['GET', "#^/admin/activity/$id$#", 'admin_activity_page'],
        ['GET', "#^/admin/activity/$id/edit$#", 'admin_edit'],
        ['GET', "#^/admin/activity/$id/export\.csv$#", 'admin_export_csv'],
        ['GET', '#^/admin/api/activities$#', 'admin_api_list'],
        ['POST', '#^/admin/api/activities$#', 'admin_api_create'],
        ['POST', "#^/admin/api/activity/$id$#", 'admin_api_update'],
        ['POST', "#^/admin/api/activity/$id/state$#", 'admin_api_state'],
        ['POST', "#^/admin/api/activity/$id/reset$#", 'admin_api_reset'],
        ['POST', "#^/admin/api/activity/$id/duplicate$#", 'admin_api_duplicate'],
        ['POST', "#^/admin/api/activity/$id/delete$#", 'admin_api_delete'],
        ['GET', "#^/admin/api/activity/$id/live$#", 'admin_api_live'],
        ['POST', '#^/admin/api/upload$#', 'admin_api_upload'],

        // First-run setup
        ['GET', '#^/setup$#', 'setup_page'],
        ['POST', '#^/setup$#', 'setup_page'],
    ];
}

function gm_run(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $method = $method === 'HEAD' ? 'GET' : $method;
    $path = request_path();

    if (!is_configured()) {
        if (config_file_exists()) {
            // Never reopen the wizard over an existing (but broken) config.php.
            render_error(500, 'Configuration problem', 'config.php exists but doesn’t contain valid settings. Compare it with config.example.php.');
            return;
        }
        if ($path !== '/setup') {
            if (is_api_request()) {
                json_error('This site isn’t set up yet.', 503, 'not_configured');
            }
            redirect('/setup');
        }
    }

    // Participant POSTs must come from our own scripts (blocks cross-site form posts).
    if ($method === 'POST' && strpos($path, '/api/') === 0 && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'gamify') {
        json_error('Please use the app to take part.', 400, 'bad_request');
    }
    if (is_configured()) {
        ensure_schema();
    }

    $allowed = [];
    foreach (gm_routes() as [$verb, $pattern, $handler]) {
        if (!preg_match($pattern, $path, $m)) {
            continue;
        }
        if ($verb !== $method) {
            $allowed[] = $verb;
            continue;
        }
        array_shift($m);
        $handler(...$m);
        return;
    }

    if ($allowed) {
        header('Allow: ' . implode(', ', array_unique($allowed)));
        if (is_api_request()) {
            json_error('Method not allowed.', 405, 'method');
        }
        render_error(405, 'Not allowed', 'That action isn’t available here.');
        return;
    }
    send_security_headers();
    if (is_api_request()) {
        json_error('Not found.', 404, 'not_found');
    }
    render_error(404, 'Page not found', 'Check the link or the code and try again.');
}
