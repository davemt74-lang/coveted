<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Event Opportunity / Production contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Event Opportunity / Production contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260906_event_opportunity_production.sql');
$opportunities = $read('app/event_opportunities.php');
$production = $read('app/event_production.php');
$opportunityPage = $read('admin/event-opportunities.php');
$productionPage = $read('admin/event-production.php');
$operations = $read('app/operations.php');
$navigation = $read('assets/js/event-opportunity-production-nav-v1.js');
$loader = $read('assets/js/coveted.js');

foreach (['event_production_items','event_production_notes','fk_event_production_items_event','fk_event_production_notes_event','assigned_user_id','created_by_user_id'] as $needle) {
    $contains($migration, $needle, 'migration must define durable event-production state: ' . $needle);
}

$contains($opportunities, 'function coveted_event_opportunities', 'deterministic Event Opportunity Engine is required');
$contains($opportunities, "vr.relationship_status IN ('event_venue','partner','preferred_partner','home_venue')", 'engine must use established canonical venue relationships');
$contains($opportunities, '$upcomingForGroup > 0', 'engine must suppress recommendations when the group already has future event coverage');
$contains($opportunities, 'verified_visits', 'attendance evidence is required');
$contains($opportunities, 'active_perks', 'Partner Perk evidence is required');
$contains($opportunities, 'active_campaigns', 'campaign evidence is required');
$contains($opportunities, "'suggested_draft'", 'recommendations must expose an explicit canonical draft recipe');
$contains($opportunities, "'status' => 'draft'", 'recommendations must default to draft events');
$contains($opportunities, 'coveted_event_create($admin', 'explicit Admin acceptance must use canonical event creation');
$contains($opportunities, 'coveted_event_set_location($admin', 'accepted recommendation must use canonical event location service');
$contains($opportunities, 'coveted_event_require_system_admin($admin)', 'event creation must remain System Admin authority');
$missing($opportunities, 'CREATE TABLE', 'opportunity service must not create runtime schema');
$missing($opportunities, 'ALTER TABLE', 'opportunity service must not alter runtime schema');

$contains($production, 'function coveted_event_production_schema_available', 'production service must detect migration state without runtime DDL');
$contains($production, "['planning','pre_event','arrival','live','closeout']", 'production phases are required');
$contains($production, 'function coveted_event_production_seed_defaults', 'baseline production plan is required');
$contains($production, 'function coveted_event_production_create_item', 'custom production items are required');
$contains($production, 'function coveted_event_production_update_item', 'production status/assignment updates are required');
$contains($production, 'function coveted_event_production_add_note', 'production notes are required');
$contains($production, 'function coveted_event_production_snapshot', 'canonical readiness snapshot is required');
$contains($production, 'function coveted_event_production_agent_context', 'Agent production context is required');
$contains($production, "eh.event_id = ? AND eh.user_id = ?", 'production assignment must be limited to active event hosts');
$contains($production, 'coveted_event_require_system_admin($admin)', 'production mutations must remain System Admin-only');
$contains($production, "'lead_host'", 'readiness must check lead-host coverage');
$contains($production, "'location'", 'readiness must check venue coverage');
$contains($production, "'guest_plan'", 'readiness must check guest/invitation coverage');
$contains($production, "'blocked'", 'readiness must surface blocked production work');
$missing($production, 'CREATE TABLE', 'production service must not create runtime schema');
$missing($production, 'ALTER TABLE', 'production service must not alter runtime schema');

$contains($opportunityPage, 'coveted_require_system_admin()', 'Event Opportunity workspace must be System Admin-only');
$contains($opportunityPage, 'coveted_require_csrf()', 'opportunity acceptance must require CSRF');
$contains($opportunityPage, 'Create Draft + Production Plan', 'opportunity workspace must hand accepted recommendations into production');
$contains($opportunityPage, 'coveted_event_production_seed_defaults', 'accepted opportunity must preload production plan when migration is installed');
$contains($productionPage, 'coveted_require_system_admin()', 'Event Production workspace must be System Admin-only');
$contains($productionPage, 'coveted_require_csrf()', 'production mutations must require CSRF');
$contains($productionPage, 'Admin plans. Hosts operate.', 'production workspace must preserve host/event authority boundary');
$contains($productionPage, 'Load Baseline Production Plan', 'production workspace must expose baseline checklist');
$contains($productionPage, 'PRODUCTION HISTORY', 'production workspace must expose notes/run-of-show history');

$contains($operations, "require_once __DIR__ . '/event_opportunities.php';", 'Operations must load Event Opportunity intelligence');
$contains($operations, "require_once __DIR__ . '/event_production.php';", 'Operations must load Event Production intelligence');
$contains($operations, 'coveted_event_opportunity_agent_context($actor, $pdo)', 'Admin Agent Operations snapshot must include opportunity recipes');
$contains($operations, 'coveted_event_production_agent_context($actor, $pdo)', 'Admin Agent Operations snapshot must include production readiness');
$contains($operations, "'event_opportunities'", 'Agent-visible Operations summary must expose event opportunities');
$contains($operations, "'event_production'", 'Agent-visible Operations summary must expose event production');

$contains($navigation, 'Event Opportunities', 'Admin navigation must expose Event Opportunities');
$contains($navigation, 'Production', 'Event Workspace navigation must expose Event Production');
$contains($navigation, 'data-system-sample="1"', 'navigation must respect Full System Sample Mode');
$contains($loader, 'event-opportunity-production-nav-v1.js', 'canonical JS loader must load event planning navigation');

fwrite(STDOUT, "Event Opportunity Engine + Event Production Workspace contract verified.\n");
