<?php
declare(strict_types=1);

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store, private');

$directory = __DIR__ . '/uploads/branding';
$logo = null;
foreach (['site-logo.png', 'site-logo.webp', 'site-logo.jpg'] as $filename) {
    $path = $directory . '/' . $filename;
    if (is_file($path)) {
        $logo = '/uploads/branding/' . rawurlencode($filename) . '?v=' . (int)(filemtime($path) ?: time());
        break;
    }
}

if ($logo === null) {
    echo "/* No uploaded site logo. Existing text branding remains active. */\n";
    exit;
}

$escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $logo);
?>
.cv-brand {
    min-width: 150px;
    min-height: 44px;
    background-image: url("<?= $escaped ?>");
    background-repeat: no-repeat;
    background-position: 10px center;
    background-size: contain;
    color: transparent !important;
    font-size: 0 !important;
}

.cv-brand::before {
    display: none !important;
}

.cv-admin-app[data-admin-shell="control-center-v5"] .cv-admin-brand > span:first-child {
    display: block;
    width: 150px;
    min-height: 32px;
    background-image: url("<?= $escaped ?>");
    background-repeat: no-repeat;
    background-position: left center;
    background-size: contain;
    color: transparent !important;
    font-size: 0 !important;
    letter-spacing: 0 !important;
}

@media (max-width: 720px) {
    .cv-brand {
        min-width: 124px;
        background-position: left center;
    }

    .cv-admin-app[data-admin-shell="control-center-v5"] .cv-admin-brand > span:first-child {
        width: 124px;
    }
}
