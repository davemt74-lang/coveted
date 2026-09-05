<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/site_settings.php';
require_once dirname(__DIR__) . '/app/sample_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$pdo = coveted_db();
$cityStripEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_CITY_STRIP, true, $pdo);
$networkStatsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_NETWORK_STATS, true, $pdo);

try {
    echo json_encode([
        'ok' => true,
        'sample' => true,
        'city_strip_enabled' => $cityStripEnabled,
        'network_stats_enabled' => $networkStatsEnabled,
        'cities' => $cityStripEnabled ? coveted_sample_landing_cities() : [],
        'stats' => $networkStatsEnabled ? coveted_sample_landing_network_stats() : [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo '{"ok":false}';
}
