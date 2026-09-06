<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'app/nationwide_cities.php',
    'app/sample_data.php',
    'app/site_settings.php',
    'api/landing-network.php',
    'admin/sample-data.php',
    'admin/cities.php',
    'request-invite.php',
    'assets/css/coveted-base.css',
    'assets/css/landing-network-v2.css',
    'assets/css/public-mobile-header-v2.css',
    'assets/css/coveted.css',
    'assets/js/landing-network-v2.js',
    'assets/js/public-mobile-header-v2.js',
    'assets/js/coveted.js',
    'database/migrations/20260905_nationwide_city_seed.sql',
];

$files = [];
foreach ($paths as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing landing network file: {$relative}\n");
        exit(1);
    }
    $files[$relative] = (string)file_get_contents($path);
}

$citySeed = $files['app/nationwide_cities.php'];
foreach ([
    "'San Francisco'",
    "'San Diego'",
    "'Phoenix'",
    "'Minneapolis'",
    "'New York'",
    "'Austin'",
    "'Chicago'",
    "'Miami'",
    "'Nashville'",
    "'Denver'",
    "'Seattle'",
    "'Atlanta'",
    'city_scottsdale_az',
    "status = 'archived'",
    'INSERT IGNORE INTO cities',
    '$rolloutAlreadyApplied',
] as $fragment) {
    if (!str_contains($citySeed, $fragment)) {
        fwrite(STDERR, "Nationwide city contract missing: {$fragment}\n");
        exit(1);
    }
}

$sample = $files['app/sample_data.php'];
foreach ([
    'function coveted_sample_landing_cities',
    'function coveted_sample_landing_network_stats',
    "'members' => 3248",
    "'events' => 126",
    "'business_partners' => 84",
    "'connections_made' => 9417",
] as $fragment) {
    if (!str_contains($sample, $fragment)) {
        fwrite(STDERR, "Landing sample data contract missing: {$fragment}\n");
        exit(1);
    }
}

$settings = $files['app/site_settings.php'];
foreach (['COVETED_SETTING_LANDING_CITY_STRIP', 'COVETED_SETTING_LANDING_NETWORK_STATS'] as $fragment) {
    if (!str_contains($settings, $fragment)) {
        fwrite(STDERR, "Landing visibility setting missing: {$fragment}\n");
        exit(1);
    }
}

$api = $files['api/landing-network.php'];
foreach (['city_strip_enabled', 'network_stats_enabled', 'coveted_sample_landing_cities', 'coveted_sample_landing_network_stats', "'sample' => true"] as $fragment) {
    if (!str_contains($api, $fragment)) {
        fwrite(STDERR, "Landing network endpoint contract missing: {$fragment}\n");
        exit(1);
    }
}

$admin = $files['admin/sample-data.php'];
foreach ([
    'set_landing_city_strip',
    'set_landing_network_stats',
    'Turn City Slider',
    'Turn Network Totals',
    'PUBLIC LANDING PREVIEW',
] as $fragment) {
    if (!str_contains($admin, $fragment)) {
        fwrite(STDERR, "Sample Data Admin toggle contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['admin/cities.php', 'request-invite.php'] as $relative) {
    if (!str_contains($files[$relative], 'coveted_sync_nationwide_cities')) {
        fwrite(STDERR, "Nationwide city sync missing from {$relative}.\n");
        exit(1);
    }
}

$js = $files['assets/js/landing-network-v2.js'];
foreach ([
    "fetch('/api/landing-network.php'",
    "section.setAttribute('aria-label', 'Coveted cities')",
    "name.className = 'cv-landing-city-name'",
    'hero.after(buildCitySection(payload.cities))',
    "landing.querySelector('.cv-landing-app')",
    'appSection.after(statsSection)',
    'data-count-target',
    'IntersectionObserver',
] as $fragment) {
    if (!str_contains($js, $fragment)) {
        fwrite(STDERR, "Landing network JS contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['COVETED CITIES', 'Find your city.', 'A growing network of real-world gatherings', 'data-city-prev', 'data-city-next', 'city.region'] as $forbidden) {
    if (str_contains($js, $forbidden)) {
        fwrite(STDERR, "Simplified city strip must not include: {$forbidden}\n");
        exit(1);
    }
}

$baseCss = $files['assets/css/coveted-base.css'];
if (!str_contains($baseCss, '.cv-landing-app') || !str_contains($baseCss, 'background: #f1eee8;')) {
    fwrite(STDERR, "Landing phone section background contract missing.\n");
    exit(1);
}

$css = $files['assets/css/landing-network-v2.css'];
foreach ([
    '.cv-landing-city-strip',
    '.cv-landing-city-track',
    '.cv-landing-city-name',
    'color: rgba(17,17,17,.42)',
    'font-size: clamp(14px, 1.45vw, 19px)',
    '.cv-landing-network-stats',
    'background: #f1eee8',
    '.cv-landing-stat-grid',
    'border-top: 1px solid #d2cec4',
] as $fragment) {
    if (!str_contains($css, $fragment)) {
        fwrite(STDERR, "Landing network CSS contract missing: {$fragment}\n");
        exit(1);
    }
}

$mobileCss = $files['assets/css/public-mobile-header-v2.css'];
foreach ([
    '@media (max-width: 680px)',
    '.cv-public-invite-link',
    'margin-left: auto',
    '.cv-public-mobile-menu',
    '.cv-public-mobile-drawer',
    'position: fixed',
    '.cv-public-mobile-drawer-login',
] as $fragment) {
    if (!str_contains($mobileCss, $fragment)) {
        fwrite(STDERR, "Public mobile header CSS contract missing: {$fragment}\n");
        exit(1);
    }
}

$mobileJs = $files['assets/js/public-mobile-header-v2.js'];
foreach ([
    "a[href=\"/auth.php?action=login\"]",
    "a[href=\"/auth.php?action=register\"]",
    "menu.className = 'cv-public-mobile-menu'",
    "login.href = '/auth.php?action=login'",
    "event.key === 'Escape'",
] as $fragment) {
    if (!str_contains($mobileJs, $fragment)) {
        fwrite(STDERR, "Public mobile header JS contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($files['assets/css/coveted.css'], 'landing-network-v2-phone-match-20260905')) {
    fwrite(STDERR, "Canonical CSS entrypoint does not load the phone-matched landing network styles.\n");
    exit(1);
}
if (!str_contains($files['assets/css/coveted.css'], 'public-mobile-header-v2-20260905')) {
    fwrite(STDERR, "Canonical CSS entrypoint does not load public mobile header styles.\n");
    exit(1);
}
if (!str_contains($files['assets/js/coveted.js'], 'landing-network-v2-layout-20260905')) {
    fwrite(STDERR, "Canonical JS entrypoint does not load the revised landing network script.\n");
    exit(1);
}
if (!str_contains($files['assets/js/coveted.js'], 'public-mobile-header-v2-20260905')) {
    fwrite(STDERR, "Canonical JS entrypoint does not load public mobile header behavior.\n");
    exit(1);
}

$migration = $files['database/migrations/20260905_nationwide_city_seed.sql'];
foreach (['city_san_francisco_ca', 'city_minneapolis_mn', 'city_new_york_ny', 'city_austin_tx', 'city_scottsdale_az', 'INSERT IGNORE INTO cities'] as $fragment) {
    if (!str_contains($migration, $fragment)) {
        fwrite(STDERR, "Nationwide city migration missing: {$fragment}\n");
        exit(1);
    }
}

echo "Landing network + nationwide city + mobile header contract OK\n";
