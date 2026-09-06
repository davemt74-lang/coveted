<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing Benefit sponsorship file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Benefit sponsorship contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Benefit sponsorship contract failed: {$label}\n");
        exit(1);
    }
};
$countAtLeast = static function (string $content, string $needle, int $minimum, string $label): void {
    if (substr_count($content, $needle) < $minimum) {
        fwrite(STDERR, "Benefit sponsorship contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260906_benefit_sponsorship_proposals.sql');
$service = $read('app/benefit_sponsorships.php');
$conversion = $read('app/benefit_sponsorship_conversion.php');
$businessPage = $read('business-sponsorships.php');
$businessHost = $read('business-host.php');
$adminPage = $read('admin/benefit-sponsorships.php');
$adminUi = $read('app/admin_ui.php');
$actions = $read('app/admin_agent_actions.php');
$branding = $read('app/site_branding.php');
$agentJs = $read('assets/js/admin-agent-live-business-v1.js');
$events = $read('app/events.php');

// Durable proposal schema and deployment record.
$contains($migration, 'CREATE TABLE IF NOT EXISTS benefit_sponsorship_proposals', 'proposal migration is required');
$contains($migration, "status ENUM('submitted','declined','cancelled','converted')", 'proposal lifecycle must stay bounded');
$contains($migration, 'benefit_program_ref VARCHAR(64) NULL', 'proposal must retain converted program reference');
$contains($migration, 'UNIQUE KEY uq_benefit_sponsorship_program (benefit_program_ref)', 'one program cannot be attached to multiple sponsorship proposals');
$contains($service, 'function coveted_benefit_sponsorship_ensure_schema(', 'defensive schema creation is required for the new table');

// Partner scope is resource-scoped and relationship-scoped. Business proposals
// cannot self-attach to arbitrary Coveted groups, venues, or non-partner-visible
// draft events even when a caller manually posts an event reference.
$contains($service, 'coveted_business_require_mutable($actor, $businessId)', 'proposal creation must require mutable Business access');
$contains($service, 'coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef)', 'proposal must use canonical venue relationship scope');
$contains($service, "COALESCE(vr.benefits_enabled,0) AS benefits_enabled", 'proposal must read benefits-enabled relationship state');
$contains($service, "(int)(\$stored['benefits_enabled'] ?? 0) !== 1", 'benefit-disabled relationships must be rejected');
$countAtLeast($service, "e.status IN ('published','closed','completed')", 3, 'Business event resolution, proposal history and ROI must keep event details partner-visible only');
$contains($service, 'The selected event is not available for this business relationship.', 'invalid or hidden event refs must use a non-enumerating error');
$missing($service, "AND e.status <> 'cancelled'", 'Business proposal resolver must not accept draft events');
$contains($businessPage, 'coveted_benefit_sponsorship_create(', 'Business workspace must submit through sponsorship service');
$contains($businessPage, 'coveted_benefit_sponsorship_cancel(', 'Business workspace may cancel its submitted proposal');
$missing($businessPage, 'coveted_benefit_program_set_status(', 'Business workspace must not change program status');
$missing($businessPage, 'coveted_benefit_program_create_draft(', 'Business workspace must not create a Benefit Program directly');
$missing($businessPage, 'coveted_event_create(', 'Business workspace must not create events');
$missing($businessPage, 'coveted_event_update', 'Business workspace must not configure events');

// Conversion stays System Admin-only and uses one canonical replay-safe path.
// Acceptance is draft-only and replay-safe through a proposal-specific Builder marker.
$missing($service, 'function coveted_benefit_sponsorship_convert_to_program_draft(', 'legacy duplicate sponsorship conversion path must stay removed');
$missing($service, 'function coveted_benefit_sponsorship_recover_program_ref(', 'legacy duplicate recovery path must stay removed');
$contains($conversion, 'coveted_is_system_admin($admin)', 'proposal conversion must require System Admin');
$contains($conversion, 'coveted_benefit_program_create_draft($admin', 'accepted proposal must use canonical Benefit Program Builder');
$contains($conversion, "'created_surface' => 'merchant_sponsorship:' . (string)\$proposal['public_id']", 'proposal conversion must carry a deterministic Builder marker');
$contains($conversion, "JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.created_surface')) = ?", 'conversion replay must recover by exact Builder marker');
$contains($conversion, 'SELECT GET_LOCK(?, 5)', 'proposal conversion must serialize concurrent acceptance');
$contains($conversion, "'status' => 'draft'", 'new conversion result must be draft');
$missing($conversion, 'coveted_benefit_program_set_status(', 'proposal conversion must never launch the program');
$missing($conversion, 'UPDATE events', 'proposal conversion must not alter events');
$contains($adminPage, 'coveted_benefit_sponsorship_convert_proposal_to_draft(', 'Admin review page must use replay-safe conversion entry point');
$contains($adminPage, 'Acceptance did not launch it.', 'Admin UI must state conversion is not launch');

// Partner ROI uses canonical issuance/claim state and exact return linkage, not
// inferred person-level CRM behavior.
$contains($service, "JSON_UNQUOTE(JSON_EXTRACT(followup.metadata_json,'$.source_reward_issuance_id'))=source.public_id", 'ROI return attribution must use exact source issuance linkage');
$contains($service, "followup_campaign.trigger_key IN ('return_visit','guest_return')", 'ROI return attribution must require canonical return triggers');
$contains($service, 'Aggregate sponsorship performance only.', 'partner ROI privacy statement is required');
$missing($service, 'u.email', 'partner ROI/Agent context must not expose user email');
$missing($service, 'u.phone', 'partner ROI/Agent context must not expose user phone');
$missing($service, 'display_name', 'partner ROI/Agent context must not expose member names');

// Agent sees proposal/ROI context but submission never becomes executable just
// because the partner sent it. Acceptance requires an explicit System Admin goal.
$contains($branding, "require_once __DIR__ . '/benefit_sponsorships.php';", 'Agent snapshot must load sponsorship context');
$contains($branding, "\$operations['benefit_sponsorships']", 'Agent operations context must include sponsorships');
$contains($branding, "'kind' => 'sponsorship_review'", 'pending proposals must become visible Agent review signals');
$contains($branding, "'task_sync' => false", 'proposal review signals must not sync into executable tasks');
$contains($branding, "'execution_ready' => false", 'proposal review signals must not self-authorize execution');
$contains($actions, "'convert_sponsorship_proposal_to_draft'", 'Agent must have bounded explicit conversion action');
$contains($actions, "'arguments' => ['proposal_ref']", 'Agent conversion action must accept only proposal ref');
$contains($actions, 'coveted_benefit_sponsorship_convert_proposal_to_draft($admin, $proposalRef)', 'Agent conversion must call canonical replay-safe sponsorship conversion');
$contains($actions, 'A merchant sponsorship submission is only a proposal', 'Agent protocol must preserve proposal trust boundary');
$contains($actions, 'Conversion creates a draft only.', 'Agent protocol must preserve draft-only conversion');
$missing($actions, "'launch_sponsorship'", 'there must be no direct sponsorship launch action');
$missing($actions, "'resize_sponsorship'", 'there must be no direct sponsorship economics action');
$contains($agentJs, "label: 'Sponsor proposals'", 'Admin Agent must expose sponsorship review starter');
$contains($agentJs, 'A submitted proposal is not authorization to accept it.', 'starter must preserve explicit acceptance boundary');

// Discoverability without changing event authority.
$contains($businessHost, '/business-sponsorships.php?business=', 'Business Host must expose Benefits / Sponsorship workspace');
$contains($businessHost, 'Propose Sponsored Benefit / View ROI', 'Rewards panel must hand off to sponsorship workspace');
$contains($adminUi, "'/admin/benefit-sponsorships.php'", 'Admin VALUE navigation must expose sponsorship review');
$contains($events, 'coveted_event_require_system_admin($actor);', 'event creation/configuration remains System Admin-only');

fwrite(STDOUT, "Merchant-sponsored Benefit Programs and Partner ROI contract verified.\n");
