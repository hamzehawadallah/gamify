<?php
/**
 * Public pages: join screen, participant activity page and the presenter view.
 */
declare(strict_types=1);

function page_home(): void
{
    send_security_headers();
    render('home', [
        'title' => app_name(),
        'bodyClass' => 'page-home',
        'scripts' => ['js/home.js'],
    ]);
}

function page_participant(string $code): void
{
    send_security_headers();
    $activity = activity_by_code($code);
    $data = participant_payload($activity, participant_current());
    $data['code'] = $code;
    if (!$activity) {
        http_response_code(404);
    }
    render('participant', [
        'title' => $activity ? $activity['title'] : 'Activity not found',
        'bodyClass' => 'page-participant',
        'data' => $data,
        'scripts' => ['js/poll.js', 'js/results.js', 'js/participant.js'],
    ]);
}

function present_key_ok(array $activity): bool
{
    $key = (string) ($_GET['key'] ?? '');
    return ($key !== '' && hash_equals((string) $activity['present_key'], $key)) || admin_user() !== null;
}

function page_present(string $code): void
{
    send_security_headers(true);
    $activity = activity_by_code($code);
    if (!$activity || !present_key_ok($activity)) {
        render_error(404, 'Presentation not found', 'This presenter link is incomplete or no longer valid. Copy it again from the admin page.');
        return;
    }
    $theme = in_array($_GET['theme'] ?? '', ['light', 'dark'], true) ? $_GET['theme'] : 'auto';
    render('present', [
        'title' => $activity['title'],
        'bodyClass' => 'page-present',
        'theme' => $theme,
        'data' => [
            'key' => (string) ($_GET['key'] ?? ''),
            'qr' => isset($_GET['qr']) ? $_GET['qr'] !== '0' : null,
            'question' => max(0, (int) ($_GET['q'] ?? 0)), // 0 = all questions, N = only question N
            'live' => activity_live_payload($activity, false),
        ],
        'scripts' => ['vendor/qrcode.js', 'js/results.js', 'js/present.js'],
    ]);
}
