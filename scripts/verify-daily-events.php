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
$worker = $read('scripts/reconcile-lifecycle.php');
$loyalty = $read('app/loyalty.php');
$events = $read('app/events.php');
$workflow = $read('.github/workflows/php-lint.yml');

// Durable Daily Event relationship. The event remains canonical; this table
// adds partner location, threshold reward and unlock state.
$contains($migration, 'CREATE TABLE IF NOT EXISTS daily_event_opportunities', 'Daily Event migration must exist');
$contains($schema, 'CREATE TABLE IF NOT EXISTS daily_event_opportunities', 'fresh installs need Daily Event schema');
foreach (['event_id BIGINT UNSIGNED NOT NULL UNIQUE','business_id BIGINT UNSIGNED NOT NULL','location_id BIGINT UNSIGNED NOT NULL','reward_campaign_id BIGINT UNSIGNED NOT NULL','attendance_threshold INT UNSIGNED NOT NULL'] as $needle) {
    $contains($migration, $needle, 'Daily Event partner/reward scope must be durable and mandatory');
}
$contains($migration, 'CONSTRAINT chk_daily_event_threshold CHECK (attendance_threshold > 0)', 'attendance threshold must be positive');
$contains($installer, "database/schema-daily-events.sql", 'installer must apply Daily Event schema fragment');

// System Admin remains the only event creator/configurator. Business Partners
// are resource participants, not a new event authority.
$contains($service, 'coveted_event_require_system_admin($actor);', 'Daily Event creation/status requires System Admin');
$contains($events, 'coveted_event_require_system_admin($actor);', 'canonical event authority must remain System Admin-only');
$contains($service, "vr.benefits_enabled=1", 'Daily Events require benefit-enabled venue relationship');
$contains($service, "vr.relationship_status IN ('event_venue','partner','preferred_partner','home_venue')", 'Daily Events require established venue relationship');
$contains($service, "business_claim_codes", 'partner location must have claim-code capability');
$contains($service, "status='active'", 'partner resources must be active');
$contains($service, "'regular', 'group'", 'Daily Event must use canonical group-audience event');
$contains($service, "0, 'immediate'", 'Daily Event v1 disables plus-one and reveals partner location immediately');
$missing($partner, 'coveted_event_create(', 'Business Partner workspace must not create events');
$missing($partner, 'coveted_event_set_status(', 'Business Partner workspace must not configure event status');

// Group reward uses a dedicated manual Business campaign. Generic attendance
// automation must not issue it before the group threshold is reached.
$contains($service, "c.trigger_key='manual'", 'Daily Event reward campaign must be manual-triggered');
$contains($service, "rt.claim_mode='location_code'", 'Daily Event reward must use Business location claim system');
$contains($service, 'NOT EXISTS (SELECT 1 FROM campaign_event_links cel WHERE cel.campaign_id=c.id)', 'Daily Event reward campaign must be dedicated before assignment');
$contains($service, 'The group reward pool must cover the Daily Event capacity', 'reward inventory must cover every possible verified attendee');
$contains($service, 'INSERT INTO campaign_event_links', 'dedicated campaign must be canonically linked to the event');
$contains($service, ">= deo.attendance_threshold", 'group reward must wait for verified attendance threshold');
$contains($service, "CONCAT('daily-group-reward:'", 'reward issuance must use deterministic idempotency key');
$contains($service, 'coveted_reward_issue(', 'threshold reward must use canonical reward issuance service');

// Member opt-in + location-code attendance. Claim code proves the real-world
// partner visit; an RSVP alone is not attendance.
$contains($member, "'rsvp_attending'", 'member must be able to opt in');
$contains($service, "SELECT response FROM event_rsvps", 'check-in must require RSVP state');
$contains($service, "!== 'attending'", 'only attending RSVP can check in');
$contains($service, "gm.membership_status='active'", 'check-in must require active group membership');
$contains($service, 'coveted_claim_code_verify_for_location(', 'check-in must verify canonical Business claim code at exact location');
$contains($service, 'COVETED_DAILY_EVENT_CHECKIN_EARLY_MINUTES', 'check-in needs bounded early window');
$contains($service, 'COVETED_DAILY_EVENT_CHECKIN_LATE_MINUTES', 'check-in needs bounded late window');
$contains($service, "INSERT INTO event_attendance", 'verified partner visit must write canonical event attendance');
$contains($service, "'verification_method' => 'partner_location_code'", 'audit must identify claim-code attendance verification');
$contains($service, "'daily-checkin-member-event|'", 'check-in code attempts must be throttled by member/event');
$contains($service, "'daily-checkin-member-ip|'", 'check-in code attempts must be throttled by member/IP');

// One attendance record feeds all systems. Daily Events do not create a second
// points currency or a boosted Daily Event attendance score.
$contains($loyalty, 'FROM event_attendance ea', 'Loyalty must continue deriving attendance from canonical event_attendance');
$contains($loyalty, "e.status='completed'", 'Loyalty attendance remains completion-gated');
$contains($loyalty, 'const COVETED_LOYALTY_ATTENDANCE_POINTS = 100;', 'Daily Event attendance must use normal verified attendance points');
$missing($service, 'loyalty_point_ledger', 'Daily Event service must not write a second/direct Loyalty ledger path');
$missing($service, 'COVETED_LOYALTY_ATTENDANCE_POINTS +', 'Daily Event service must not boost attendance points');

// Existing worker remains the only scheduler.
$contains($worker, "require_once dirname(__DIR__) . '/app/daily_events.php';", 'existing lifecycle worker must load Daily Events');
$contains($worker, '$daily = coveted_daily_event_reconcile($limit);', 'existing lifecycle worker must reconcile Daily Event rewards');
$contains($worker, 'Coveted Daily Events:', 'worker output must expose Daily Events');
$contains($worker, "!empty(\$daily['more_work_possible'])", 'Daily Event backlog must affect worker exit');
$contains($worker, "(int)\$daily['failures'] > 0", 'Daily Event failures must affect worker exit');

// Partner reporting stays aggregate and member points remain private.
$contains($partner, 'Partner reporting is aggregate.', 'Business Partner privacy boundary must be explicit');
$missing($partner, 'display_name', 'Business Partner dashboard must not expose member names');
$missing($partner, 'email', 'Business Partner dashboard must not expose member emails');
$missing($partner, 'loyalty_point_ledger', 'Business Partner dashboard must not expose private point ledger');
$contains($member, 'same canonical attendance record flows into your private Coveted Loyalty points', 'member UI must explain Loyalty integration');
$contains($admin, 'Partners never gain event-creation authority.', 'Admin UI must explain partner authority boundary');

$contains($workflow, 'php scripts/verify-daily-events.php', 'Daily Events contract must run in CI');

fwrite(STDOUT, "Daily Events / partnered opportunity contract verified.\n");
