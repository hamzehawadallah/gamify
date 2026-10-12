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

/* ---------- PowerPoint add-in ---------- */

/** The page PowerPoint loads inside the slide: asks for a presenter link once, then shows it live. */
function page_powerpoint(): void
{
    send_security_headers(true);
    render('powerpoint', [
        'title' => 'Live results',
        'bodyClass' => 'page-pp',
        'headScripts' => ['https://appsforoffice.microsoft.com/lib/1/hosted/office.js'],
        'scripts' => ['js/powerpoint.js'],
    ]);
}

/** Office add-in manifest for this site. Install it once in PowerPoint (see README). */
function page_powerpoint_manifest(): void
{
    $base = rtrim(absolute_url(''), '/');
    $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $base);
    // A stable id per installation, so re-installing updates the same add-in.
    $h = hash('sha256', app_secret() . '|powerpoint-addin');
    $id = sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    $x = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $name = app_name();

    no_cache();
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)) . '-powerpoint-addin.xml"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo <<<XML
<OfficeApp xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:type="ContentApp">
  <Id>{$id}</Id>
  <Version>1.1.0.0</Version>
  <ProviderName>{$x($name)}</ProviderName>
  <DefaultLocale>en-US</DefaultLocale>
  <DisplayName DefaultValue="{$x($name . ' Live Results')}"/>
  <Description DefaultValue="{$x('Show live ' . $name . ' voting results on a slide. Paste a presenter link once; results update during the slideshow.')}"/>
  <IconUrl DefaultValue="{$x($base . '/assets/img/addin-32.png')}"/>
  <HighResolutionIconUrl DefaultValue="{$x($base . '/assets/img/addin-64.png')}"/>
  <SupportUrl DefaultValue="{$x($base . '/')}"/>
  <AppDomains>
    <AppDomain>{$x($origin)}</AppDomain>
  </AppDomains>
  <Hosts>
    <Host Name="Presentation"/>
  </Hosts>
  <DefaultSettings>
    <SourceLocation DefaultValue="{$x($base . '/powerpoint')}"/>
    <RequestedWidth>960</RequestedWidth>
    <RequestedHeight>540</RequestedHeight>
  </DefaultSettings>
  <Permissions>ReadWriteDocument</Permissions>
  <AllowSnapshot>true</AllowSnapshot>
</OfficeApp>

XML;
    exit;
}
