<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing Partner Profile CRM file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Partner Profile CRM contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Partner Profile CRM contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260906_partner_profile_crm.sql');
$service = $read('app/partner_crm.php');
$agent = $read('app/partner_crm_agent.php');
$page = $read('partner-profile.php');
$nav = $read('assets/js/partner-profile-nav-v1.js');
$loader = $read('assets/js/coveted.js');
$partnerOps = $read('app/partner_opportunities.php');
$branding = $read('app/site_branding.php');
$brain = $read('app/admin_agent_brain.php');
$chat = $read('api/admin-agent-chat.php');
$events = $read('app/events.php');

// Canonical data model: business identity once, relationship CRM separately.
foreach (['business_profiles','partner_relationship_crm','partner_contacts','partner_notes','partner_interactions','partner_followups'] as $table) {
    $contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table, $table . ' table is required');
}
$contains($migration, 'UNIQUE KEY uq_partner_relationship_crm (group_id,location_id)', 'CRM ownership must be unique per group x location relationship');
$contains($migration, "preferred_contact ENUM('email','phone','text','in_person','other')", 'partner contact preference must be structured');
$contains($migration, "interaction_type ENUM('call','email','text','meeting','in_person','other')", 'partner conversations must be structured');
$contains($migration, "status ENUM('open','completed','cancelled')", 'follow-up lifecycle must be structured');

// Relationship authority and privacy.
$contains($service, 'coveted_venue_relationship_resolve(', 'Partner Profiles must resolve a real canonical venue relationship');
$contains($service, 'coveted_business_actor_can_manage', 'business identity edits must retain Business Admin authority');
$contains($service, 'coveted_is_system_admin($admin)', 'private CRM mutations must require System Admin authority');
$contains($service, 'function coveted_partner_contact_save', 'Partner Contacts must be supported');
$contains($service, 'function coveted_partner_note_add', 'Partner Notes must be supported');
$contains($service, 'function coveted_partner_interaction_add', 'Partner conversations/interactions must be supported');
$contains($service, 'function coveted_partner_followup_add', 'Partner follow-ups must be supported');
$contains($service, 'function coveted_partner_profile_timeline', 'canonical relationship timeline must exist');
$contains($service, 'coveted_venue_relationship_events(', 'timeline must reuse canonical event history');
$contains($service, 'coveted_daily_event_business_rows(', 'timeline must reuse canonical Daily Events');
$contains($service, 'coveted_partner_perks_for_relationship(', 'timeline must reuse canonical Partner Perks');
$contains($service, 'coveted_audit(', 'CRM mutations must enter canonical audit memory');

// Agent brain + chat integration uses a bulk reader rather than N+1 UI readers.
$contains($agent, 'function coveted_partner_crm_agent_context_v2', 'optimized Partner CRM Agent context must exist');
$contains($agent, 'Raw email/phone fields are never selected into this context', 'broad Agent context must exclude raw partner contact endpoints');
$contains($agent, "FROM partner_contacts pc", 'Agent context must read partner contacts in bulk');
$contains($agent, "FROM partner_followups pf", 'Agent context must read partner follow-ups in bulk');
$contains($agent, "FROM partner_interactions pi", 'Agent context must read partner interactions in bulk');
$contains($agent, "FROM partner_notes pn", 'Agent context must include recent partner notes');
$contains($agent, "'recent_activity'", 'Agent context must expose unified recent CRM activity');
$contains($agent, "'partner_followup_overdue'", 'overdue partner follow-ups must become Agent opportunities');
$contains($agent, "'partner_contact_missing'", 'missing partner contacts must become Agent opportunities');
$contains($agent, "'partner_owner_missing'", 'missing relationship owners must become Agent opportunities');
$contains($partnerOps, "require_once __DIR__ . '/partner_crm_agent.php';", 'Partner Opportunities must load optimized Partner CRM Agent intelligence');
$contains($partnerOps, 'coveted_partner_crm_agent_context_v2(', 'Partner CRM must join canonical Partner Opportunities context');
$contains($partnerOps, "'crm' => \$partnerCrm", 'Partner CRM snapshot must be preserved for the Agent brain');
$contains($branding, "'partner_opportunities'", 'site enrichment must preserve Partner Opportunities in Agent operations');
$contains($brain, "'operations' => \$snapshot['operations']", 'Agent provider context must include enriched operations');
$contains($chat, 'coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin))', 'Agent Chat must rebuild enriched partner context on each request');
$contains($chat, 'coveted_admin_agent_context_message($brain)', 'Agent Chat must send canonical brain context to the provider');

// Partner Profile is the primary relationship container.
$contains($page, 'PARTNER PROFILE', 'Partner Profile page must have an explicit identity surface');
$contains($page, 'PARTNER CONTACTS', 'contacts must live inside the Partner Profile');
$contains($page, 'FOLLOW-UPS', 'follow-ups must live inside the Partner Profile');
$contains($page, 'NOTES & CONVERSATIONS', 'notes and conversations must live inside the Partner Profile');
$contains($page, 'RELATIONSHIP TIMELINE', 'timeline must live inside the Partner Profile');
$contains($page, 'Open Agent Chat', 'System Admin must be able to move from a Partner Profile into Agent Chat');
$contains($nav, "url.pathname = '/partner-profile.php'", 'Venue Relationship directory must route to Partner Profile');
$contains($nav, "link.textContent = 'Open Partner Profile'", 'Partner Profile must become the primary relationship destination');
$contains($loader, 'partner-profile-nav-v1.js', 'application loader must activate Partner Profile navigation');

// CRM cannot gain event creation authority.
$contains($events, 'function coveted_event_require_system_admin(array $actor): void', 'System Admin event authority must remain canonical');
foreach (['coveted_event_create(', 'coveted_event_update(', 'coveted_event_set_location(', 'coveted_event_assign_host('] as $mutation) {
    $missing($service, $mutation, 'Partner CRM must not mutate events: ' . $mutation);
    $missing($page, $mutation, 'Partner Profile UI must not mutate events: ' . $mutation);
}
foreach (['CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE TABLE'] as $ddl) {
    $missing($service, $ddl, 'Partner CRM service must not modify schema at runtime: ' . $ddl);
    $missing($agent, $ddl, 'Partner CRM Agent reader must not modify schema at runtime: ' . $ddl);
}

fwrite(STDOUT, "Partner Profile CRM contract verified.\n");
