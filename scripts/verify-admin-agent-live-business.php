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
        fwrite(STDERR, "Live business analytics contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Live business analytics contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/admin_agent_live_business.php');
$enrichment = $read('app/site_branding.php');
$brain = $read('app/admin_agent_brain.php');
$starters = $read('assets/js/admin-agent-live-business-v1.js');
$jsEntry = $read('assets/js/coveted.js');

// Bounded canonical read-only analytics coverage.
$contains($service, 'function coveted_admin_agent_live_business_snapshot(', 'live business snapshot is missing');
$contains($service, 'function coveted_admin_agent_live_business_city_demand(', 'city demand report is missing');
$contains($service, 'FROM invite_requests ir', 'city demand must read canonical Invite CRM');
$contains($service, 'LIMIT 5', 'city demand must remain bounded');
$contains($service, 'function coveted_admin_agent_live_business_interest_demand(', 'interest demand report is missing');
$contains($service, 'coveted_invite_event_interest_options()', 'interest demand must use canonical interest catalog');
$contains($service, 'JSON_CONTAINS(event_interests_json, ?,', 'interest demand must use exact canonical JSON membership');
$contains($service, 'array_slice($items, 0, 6)', 'interest demand must remain context-bounded');
$contains($service, 'function coveted_admin_agent_live_business_event_attention(', 'event attention report is missing');
$contains($service, 'FROM events e', 'event attention must read canonical events');
$contains($service, "e.status IN ('draft','published')", 'event attention must be operationally scoped');
$contains($service, 'LIMIT 6', 'event/partner detail lists must remain context-bounded');
$contains($service, 'function coveted_admin_agent_live_business_partner_coverage(', 'partner coverage report is missing');
$contains($service, 'FROM businesses b', 'partner coverage must read canonical businesses');
$contains($service, "c.owner_type = 'business' AND c.status = 'active'", 'business campaign coverage must use canonical owner/status');
$contains($service, "rt.owner_type = 'business' AND rt.status = 'active'", 'business reward coverage must use canonical owner/status');
$contains($service, 'function coveted_admin_agent_live_business_host_capacity(', 'host-capacity report is missing');
$contains($service, "ur.role_key = 'attendee_host'", 'host capacity must use canonical attendee_host role');
$contains($service, "gm.group_role IN ('host','group_admin')", 'host capacity must use canonical group leadership roles');
$contains($service, 'function coveted_admin_agent_live_business_event_momentum(', 'event momentum report is missing');
$contains($service, "er.response = 'attending'", 'RSVP momentum must use canonical response state');
$contains($service, 'function coveted_admin_agent_live_business_weekly_changes(', 'weekly comparison report is missing');
$contains($service, 'INTERVAL 14 DAY', 'weekly comparison must use current and prior seven-day windows');
$contains($service, "event_type NOT LIKE 'admin.agent_%'", 'Agent self-audit noise must be excluded');
$contains($service, "'privacy' => 'Person-level names", 'person-level privacy boundary must be explicit');
$contains($service, "'trust_boundary' => 'Business names", 'stored operational labels must be explicitly treated as data, not instructions');
$contains($service, 'does not accept model-authored SQL or filters', 'arbitrary/model-authored SQL boundary must be documented');
$contains($service, 'coveted_admin_agent_live_business_safe(', 'analytics components must fail soft independently');

// This module must remain analytics-only: no provider calls, writes or free-form query input.
$missing($service, 'coveted_ai_chat(', 'live analytics must never call an AI provider itself');
$missing($service, 'INSERT INTO ', 'live analytics must never insert data');
$missing($service, 'UPDATE ', 'live analytics must never update data');
$missing($service, 'DELETE FROM ', 'live analytics must never delete data');
$missing($service, '$_GET', 'live analytics must not accept HTTP query input');
$missing($service, '$_POST', 'live analytics must not accept HTTP mutation input');
foreach (['ir.full_name', 'ir.email', 'ir.message', 'ir.admin_note', 'u.display_name', 'p.bio'] as $personField) {
    $missing($service, $personField, 'person-level field leaked into live business analytics: ' . $personField);
}

// Existing System-Admin-gated Agent enrichment owns the analytics attachment.
$contains($enrichment, "require_once __DIR__ . '/admin_agent_live_business.php';", 'Agent enrichment must load live analytics locally');
$contains($enrichment, 'coveted_admin_agent_live_business_snapshot()', 'Agent enrichment must refresh live analytics');
$contains($enrichment, "\$operations['live_business'] = \$liveBusiness;", 'live analytics must be attached to existing operations context');
$contains($enrichment, "\$issues[] = 'live_business';", 'live analytics failures must remain fail-soft');
$contains($brain, "'operations' => \$snapshot['operations'] ?? []", 'provider context must include enriched operations data');

// Query starters must use the existing chat submit path without colliding with core starters.
$contains($starters, "button.dataset.agentLiveBusinessStarter = '1';", 'live-business starter namespace is missing');
$missing($starters, 'button.dataset.agentStarter', 'live-business buttons must not collide with core starter handlers');
$contains($starters, 'Compare city demand', 'city-demand starter is missing');
$contains($starters, 'Events needing attention', 'event-attention starter is missing');
$contains($starters, 'Rank event interests', 'interest-demand starter is missing');
$contains($starters, 'Review partner coverage', 'partner-coverage starter is missing');
$contains($starters, 'Compare this week', 'weekly comparison starter is missing');
$contains($starters, 'Review host capacity', 'host-capacity starter is missing');
$contains($starters, 'Do not invent or name individual people', 'host query must preserve person-level privacy boundary');
$contains($starters, 'form.requestSubmit();', 'live-business starters must use canonical Agent submit path');
$contains($starters, 'button.textContent = label;', 'live-business starter rendering must stay DOM-safe');
$contains($jsEntry, 'admin-agent-live-business-v1-20260905', 'live-business starter cache key is missing');

fwrite(STDOUT, "Admin Agent live business analytics contract verified.\n");
