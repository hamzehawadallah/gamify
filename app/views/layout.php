<?php
/** @var string $content */
$pageTitle = (!empty($title) && $title !== app_name()) ? $title . ' · ' . app_name() : app_name();
$themeAttr = (isset($theme) && in_array($theme, ['light', 'dark'], true)) ? ' data-theme="' . e($theme) . '"' : '';
?><!doctype html>
<html lang="en"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f6f6f7">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e(app_name()) ?>">
<meta name="format-detection" content="telephone=no">
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400..800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach (($headScripts ?? []) as $headScript): ?>
<script src="<?= e($headScript) ?>"></script>
<?php endforeach; ?>
<script>
window.GM_CONFIG = <?= json_for_html(['base' => base_path(), 'appName' => app_name(), 'csrf' => $csrf ?? null]) ?>;
window.GM_ICONS = <?= json_for_html(icon_paths()) ?>;
</script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $content ?>
<div class="toast-host" aria-live="polite"></div>
<script src="<?= e(asset('js/core.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
