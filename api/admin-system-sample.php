<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/system_sample_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $admin = coveted_require_system_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'GET required.']);
        exit;
    }

    $sample = coveted_system_sample_data();
    $section = trim((string)($_GET['section'] ?? 'inventory'));
    $allowed = [
        'inventory','meta','people','role_requests','invite_crm','cities','businesses','locations','groups','events',
        'daily_events','rewards','campaigns','benefit_programs','sponsorships','loyalty','wallet','claims','distribution',
        'partner_relationships','partner_contacts','partner_notes','partner_interactions','partner_followups','partner_perks',
        'artists','artist_media','artist_appearances','notifications','operations','agent','branding','pwa','landing','member',
    ];
    if (!in_array($section, $allowed, true)) {
        throw new InvalidArgumentException('Unknown sample-data section.');
    }

    $payload = $section === 'inventory'
        ? coveted_system_sample_inventory($sample)
        : ($sample[$section] ?? null);

    echo coveted_json([
        'ok' => true,
        'sample' => true,
        'read_only' => true,
        'enabled' => coveted_system_sample_mode($admin),
        'section' => $section,
        'data' => $payload,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('System sample data endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Unable to load system sample data.']);
}
