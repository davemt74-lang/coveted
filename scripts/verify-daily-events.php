<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing Daily Events file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Daily Events contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Daily Events contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260906_daily_event_opportunities.sql');
$schema = $read('database/schema-daily-events.sql');
$installer = $read('app/installer.php');
$service = $read('app/daily_events.php');
$member = $read('daily.php');
$admin = $read('admin/daily-events.php');
$partner = $read('business-daily-events.php');
$relationships = $read('venue-relationships.php');
$worker = $read('scripts/reconcile-lifecycle.php');
$loyalty = $read('app/loyalty.php');
$events = $read('app/events.php');
$workflow = $read('.github/workflows/php-lint.yml');
$appLoader = $read('assets/js/coveted.js');
$dailyNav = $read('assets/js/daily-events-nav-v1.js');

// Durable one-event / one-partner / one-reward relationship.
$contains($migration, 'CREATE TABLE IF NOT EXISTS daily_event_opportunities', 'Daily Event migration must exist');
$contains($schema, 'CREATE TABLE IF NOT EXISTS daily_event_opportunities', 'fresh installs need Daily Event schema');
foreach ([
    'event_id BIGINT UNSIGNED NOT NULL UNIQUE',
    'business_id BIGINT UNSIGNED NOT NULL',
    'location_id BIGINT UNSIGNED NOT NULL',
    'reward_campaign_id BIGINT UNSIGNED NOT NULL',
    'attendance_threshold INT UNSIGNED NOT NULL',
    'loyalty_points INT UNSIGNED NOT NULL DEFAULT 100',
    'UNIQUE KEY uq_daily_event_campaign (reward_campaign_id)',
] as $needle) {
    $contains($migration, $needle, 'Daily Event partner/reward/points scope must be durable and one-to-one');
    $contains($schema, $needle, 'fresh-install Daily Event scope must match production migration');
}
$contains($migration, 'CONSTRAINT chk_daily_event_threshold CHECK (attendance_threshold > 0)', 'attendance threshold must be positive');
$contains($migration, 'CONSTRAINT chk_daily_event_loyalty_points CHECK (loyalty_points <= 10000)', 'Daily Event point value must be bounded');
$contains($installer, "database/schema-daily-events.sql", 'installer must apply Daily Event schema fragment');

// System Admin remains the only event creator/configurator.
$contains($service, 'coveted_event_require_system_admin($actor);', 'Daily Event creation/status requires System Admin');
$contains($events, 'coveted_event_require_system_admin($actor);', 'canonical event authority must remain System Admin-only');
$contains($service, "vr.benefits_enabled=1", 'Daily Events require benefit-enabled venue relationship');
$contains($service, "vr.relationship_status IN ('event_venue','partner','preferred_partner','home_venue')", 'Daily Events require established venue relationship');
$contains($service, 'business_claim_codes', 'partner location must have claim-code capability');
$contains($service, "'regular', 'group'", 'Daily Event must use canonical group-audience event');
$contains($service, "0, 'immediate'", 'Daily Event v1 disables plus-one and reveals partner location immediately');
$missing($partner, 'coveted_event_create(', 'Business Partner workspace must not create events');
$missing($partner, 'coveted_event_set_status(', 'Business Partner workspace must not configure event status');

// Admin defines the private point value; group and lifetime ledgers receive the same net event value.
$contains($admin, 'name="loyalty_points"', 'Admin Daily Event form must define a point value');
$contains($admin, 'min="0" max="10000"', 'Admin point input must stay within the service bound');
$contains($service, 'COVETED_DAILY_EVENT_MAX_LOYALTY_POINTS = 10000', 'service must bound Admin-defined Daily Event points');
$contains($service, "\$loyaltyPoints = (int)(\$data['loyalty_points']", 'creation must read Admin-defined point value');
$contains($service, 'attendance_threshold,loyalty_points,status,created_by', 'Daily Event creation must persist points atomically');
$contains($service, "'loyalty_points' => \$loyaltyPoints", 'Daily Event audit must preserve configured point value');
$contains($member, "['loyalty_points']", 'member Daily Event feed must show event value');
$contains($partner, "['loyalty_points']", 'Business Partner may see event policy without member balances');

// The group reward campaign is genuinely dedicated and unused before assignment.
$contains($service, "c.trigger_key='manual'", 'Daily Event reward campaign must be manual-triggered');
$contains($service, "rt.claim_mode='location_code'", 'Daily Event reward must use Business location claim system');
$contains($service, 'NOT EXISTS (SELECT 1 FROM campaign_event_links cel WHERE cel.campaign_id=c.id)', 'campaign option must be unlinked');
$contains($service, 'NOT EXISTS (SELECT 1 FROM reward_issuances ri WHERE ri.campaign_id=c.id)', 'campaign option must have no prior issuance');
$contains($service, 'NOT EXISTS (SELECT 1 FROM daily_event_opportunities deo WHERE deo.reward_campaign_id=c.id)', 'campaign option must not already belong to a Daily Event');
$contains($service, 'SELECT id FROM reward_issuances WHERE campaign_id=? LIMIT 1 FOR UPDATE', 'server-side assignment must recheck campaign is unused');
$contains($service, 'SELECT id FROM daily_event_opportunities WHERE reward_campaign_id=? LIMIT 1 FOR UPDATE', 'server-side assignment must recheck campaign is unassigned');
$contains($service, 'The group reward pool must cover the Daily Event capacity', 'reward inventory must cover every possible verified attendee');
$contains($service, '$latestIssueTs = $eventEndTs + (COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES * 60);', 'reward validity must account for late check-in window');
$contains($service, "strtotime((string)\$campaign['reward_expires_at']) <= \$latestIssueTs", 'reward must remain valid through late check-in');
$contains($service, 'INSERT INTO campaign_event_links', 'dedicated campaign must be linked to canonical event');

// Threshold is based only on verified attendance and reward issuance is canonical + terminal-idempotent.
$contains($service, "WHERE status IN ('checked_in','attended','left_early')", 'threshold must count verified attendance states');
$contains($service, '(deo.reward_unlocked_at IS NOT NULL OR totals.attendance_count >= deo.attendance_threshold)', 'threshold unlock must remain durable after reaching target');
$contains($service, "CONCAT('daily-group-reward:'", 'reward issuance needs deterministic idempotency key');
$contains($service, "WHERE ri.idempotency_key=CONCAT('daily-group-reward:',deo.id,':',ea.user_id)\n              )", 'any prior issuance key, including cancelled, must be terminal for automatic reissue');
$contains($service, 'coveted_reward_issue(', 'threshold reward must use canonical reward issuance service');

// Browser can submit only the Daily Event ref; the service resolves the canonical event for RSVP.
$contains($service, 'function coveted_daily_event_set_rsvp', 'Daily Event must provide a bound RSVP service');
$contains($service, "SELECT e.public_id AS event_ref", 'bound RSVP service must resolve its own event');
$contains($member, 'coveted_daily_event_set_rsvp(', 'member RSVP must use Daily Event-bound service');
$missing($member, "\$_POST['event_ref']", 'member POST must not trust a browser-supplied event ref');
$missing($member, 'name="event_ref"', 'member forms must not submit a separate event ref');

// Claim code proves the real-world partner visit; RSVP alone is not attendance.
$contains($service, 'SELECT response FROM event_rsvps', 'check-in must require RSVP state');
$contains($service, "!== 'attending'", 'only attending RSVP can check in');
$contains($service, "gm.membership_status='active'", 'check-in must require active group membership');
$contains($service, 'coveted_claim_code_verify_for_location(', 'check-in must verify canonical Business claim code at exact location');
$contains($service, 'COVETED_DAILY_EVENT_CHECKIN_EARLY_MINUTES', 'check-in needs bounded early window');
$contains($service, 'COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES', 'check-in needs bounded late window');
$contains($service, 'INSERT INTO event_attendance', 'verified partner visit must write canonical event attendance');
$contains($service, "'verification_method' => 'partner_location_code'", 'audit must identify claim-code attendance verification');
$contains($service, "'daily-checkin-member-event|'", 'check-in attempts must be throttled by member/event');
$contains($service, "'daily-checkin-member-ip|'", 'check-in attempts must be throttled by member/IP');

// Canonical Loyalty records the attendance fact first; append-only adjustment yields exact Admin value.
$contains($loyalty, 'FROM event_attendance ea', 'Loyalty must continue deriving attendance from canonical event_attendance');
$contains($loyalty, "e.status='completed'", 'Loyalty attendance remains completion-gated');
$contains($loyalty, 'const COVETED_LOYALTY_ATTENDANCE_POINTS = 100;', 'canonical attendance baseline remains intact');
$contains($service, 'function coveted_daily_event_loyalty_reconcile', 'configured-point reconciler must exist');
$contains($service, "source_type='verified_attendance'", 'point adjustment must wait for canonical attendance ledger entry');
$contains($service, "source_type='daily_event_points'", 'point adjustment must be idempotent');
$contains($service, '$adjustment = $configured - $basePoints;', 'point adjustment must produce exact configured total');
$contains($service, 'coveted_loyalty_insert_points(', 'Daily Event points must use canonical append-only writer');
$contains($service, "'configured_total_points' => \$configured", 'ledger metadata must preserve configured total');
$missing($service, 'INSERT INTO loyalty_point_ledger', 'Daily Event service must not bypass canonical Loyalty writer');

// Existing worker remains the only scheduler and adjustment runs after baseline Loyalty.
$contains($worker, "require_once dirname(__DIR__) . '/app/daily_events.php';", 'lifecycle worker must load Daily Events');
$contains($worker, '$daily = coveted_daily_event_reconcile($limit);', 'worker must reconcile threshold rewards');
$contains($worker, '$loyalty = coveted_loyalty_reconcile($limit);', 'canonical Loyalty pass must remain');
$contains($worker, '$dailyLoyalty = coveted_daily_event_loyalty_reconcile($limit);', 'worker must reconcile configured Daily points');
if (strpos($worker, '$dailyLoyalty = coveted_daily_event_loyalty_reconcile($limit);') < strpos($worker, '$loyalty = coveted_loyalty_reconcile($limit);')) {
    fwrite(STDERR, "Daily Events contract failed: configured point reconciliation must run after canonical Loyalty.\n");
    exit(1);
}
$contains($worker, "!empty(\$daily['more_work_possible'])", 'Daily reward backlog must affect worker exit');
$contains($worker, "!empty(\$dailyLoyalty['more'])", 'Daily point backlog must affect worker exit');
$contains($worker, "(int)\$daily['failures'] > 0", 'Daily reward failures must affect worker exit');
$contains($worker, "(int)\$dailyLoyalty['failures'] > 0", 'Daily point failures must affect worker exit');

// Discoverability follows the existing client-side shell refinement architecture.
$contains($appLoader, '/assets/js/daily-events-nav-v1.js', 'application loader must load Daily navigation');
$contains($dailyNav, "link.href = '/daily.php';", 'member navigation must expose Daily Events');
$contains($dailyNav, '/admin/daily-events.php', 'Admin Community navigation must expose Daily Events');
$contains($dailyNav, '/business-daily-events.php?business=', 'Business workspace must expose scoped Daily Events');
$contains($dailyNav, 'encodeURIComponent(businessRef)', 'Business resource ref must be safely encoded');
$contains($workflow, 'node --check assets/js/daily-events-nav-v1.js', 'Daily navigation JavaScript must be syntax-checked in CI');

// Existing Partner Relationship Management must absorb Daily Events instead of creating a parallel relationship model.
$contains($relationships, "require_once __DIR__ . '/app/daily_events.php';", 'venue relationship workspace must load Daily Event service');
$contains($relationships, 'coveted_daily_event_business_rows(', 'venue relationship workspace must use canonical scoped Daily Event reporting');
$contains($relationships, '$dailyEventsByEventRef', 'relationship event history must map Daily Events by canonical event ref');
$contains($relationships, 'Daily Event partner', 'relationship detail must identify Daily Event relationships');
$contains($relationships, 'Partnered event performance', 'relationship detail must surface Daily Event performance');
$contains($relationships, 'Daily Event group rewards issued', 'relationship portfolio must surface Daily Event reward outcomes');
$contains($relationships, 'Threshold', 'relationship event history must surface Daily Event attendance threshold');
$contains($relationships, "['loyalty_points']", 'relationship event history may show event policy value without exposing private member balances');
$contains($relationships, "['active_checkin_codes']", 'relationship event history must surface location check-in readiness');
$contains($relationships, '/business-daily-events.php?business=', 'relationship workspace must link to the scoped Daily Events view');
$missing($relationships, 'display_name', 'relationship Daily Event integration must not expose member names');
$missing($relationships, 'loyalty_point_ledger', 'relationship Daily Event integration must not expose private point ledger');

// Business Partner reporting stays aggregate; member identity/points remain private.
$contains($partner, 'Partner reporting is aggregate', 'Business Partner privacy boundary must be explicit');
$missing($partner, 'display_name', 'Business Partner dashboard must not expose member names');
$missing($partner, 'email', 'Business Partner dashboard must not expose member emails');
$missing($partner, 'loyalty_point_ledger', 'Business Partner dashboard must not expose private point ledger');
$contains($member, 'private Coveted Loyalty point', 'member UI must explain configured private Loyalty value');
$contains($admin, 'Partners never gain event-creation authority.', 'Admin UI must explain partner authority boundary');

$contains($workflow, 'php scripts/verify-daily-events.php', 'Daily Events contract must run in CI');

fwrite(STDOUT, "Daily Events / partnered opportunity contract verified.\n");
