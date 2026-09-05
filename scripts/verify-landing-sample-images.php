<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$samplePath = $root . '/app/sample_data.php';
$indexPath = $root . '/index.php';
$cssIndexPath = $root . '/assets/css/coveted.css';
$cssPath = $root . '/assets/css/landing-event-images.css';
$jsIndexPath = $root . '/assets/js/coveted.js';
$jsPath = $root . '/assets/js/landing-event-images.js';

foreach ([$samplePath, $indexPath, $cssIndexPath, $cssPath, $jsIndexPath, $jsPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing landing sample image contract file: {$path}\n");
        exit(1);
    }
}

$sample = (string)file_get_contents($samplePath);
$index = (string)file_get_contents($indexPath);
$cssIndex = (string)file_get_contents($cssIndexPath);
$css = (string)file_get_contents($cssPath);
$jsIndex = (string)file_get_contents($jsIndexPath);
$js = (string)file_get_contents($jsPath);

foreach (['Rooftop Social', 'Private Dinner', 'Artist Session', 'sample-mystery-gathering'] as $fragment) {
    if (!str_contains($sample, $fragment)) {
        fwrite(STDERR, "Landing sample event missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($index, "'Preview · '")) {
    fwrite(STDERR, "Landing sample cards must retain the Preview marker used to distinguish synthetic events.\n");
    exit(1);
}

foreach ([
    'hero-rooftop.png',
    'sunset-dinner-hero.webp',
    'vinyl-and-cocktails-hero.webp',
    'saturday-night-supper-club-hero.webp',
    "meta.includes('Preview ·')",
    "card.prepend(media)",
    "image.loading = 'lazy'",
] as $fragment) {
    if (!str_contains($js, $fragment)) {
        fwrite(STDERR, "Landing sample image interaction contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['landing-event-images.css', 'landing-event-images.js'] as $asset) {
    $haystack = str_ends_with($asset, '.css') ? $cssIndex : $jsIndex;
    if (!str_contains($haystack, $asset)) {
        fwrite(STDERR, "Landing sample image asset not loaded by canonical entrypoint: {$asset}\n");
        exit(1);
    }
}

foreach (['.cv-landing-event-media', 'object-fit: cover', 'aspect-ratio: 16 / 9'] as $fragment) {
    if (!str_contains($css, $fragment)) {
        fwrite(STDERR, "Landing sample image style contract missing: {$fragment}\n");
        exit(1);
    }
}

$images = [
    'assets/images/landing/hero-rooftop.png',
    'assets/images/sample/events/sunset-dinner-hero.webp',
    'assets/images/sample/events/vinyl-and-cocktails-hero.webp',
    'assets/images/sample/events/saturday-night-supper-club-hero.webp',
];

foreach ($images as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) < 100) {
        fwrite(STDERR, "Missing landing event image: {$relative}\n");
        exit(1);
    }
}

echo "Landing sample event image contract OK\n";
