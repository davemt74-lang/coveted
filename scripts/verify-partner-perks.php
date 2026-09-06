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
        fwrite(STDERR, "Partner Perks contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Partner Perks contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/partner_perks.php');
$page = $read('partner-perks.php');
$worker = $read('scripts/reconcile-lifecycle.php');
$migration = $read('database/migrations/20260906_partner_perks.sql');
$loader = $read('assets/js/coveted.js');
$nav = $read('assets/js/partner-perks-v1.js');
$events = $read('app/events.php');

// Persistent relationship layer: no duplicate wallet/reward engine.
$contains($migration, 'CREATE TABLE IF NOT EXISTS partner_perks', 'Partner Perks table is required');
$contains($migration, 'business_id BIGINT UNSIGNED NOT NULL', 'perks must be business-scoped');
$contains($migration, 'group_id BIGINT UNSIGNED NOT NULL', 'perks must be group-scoped');
$contains($migration, 'location_id BIGINT UNSIGNED NOT NULL', 'perks must be location-scoped');
$contains($migration, 'campaign_id BIGINT UNSIGNED NOT NULL', 'perks must reuse canonical campaigns');
$contains($migration, "ENUM('once','monthly','manual')", 'supported distribution modes are required');
$contains($migration, 'uq_partner_perk_relationship_campaign', 'same campaign cannot be duplicated on one relationship');
$contains($migration, 'fk_partner_perks_campaign', 'campaign foreign key is required');
$contains($migration, 'fk_partner_perks_location', 'location foreign key is required');
$contains($migration, 'chk_partner_perks_window', 'perk time window must be bounded correctly');

// Relationship/business authority and campaign coherence.
$contains($service, 'coveted_business_actor_can_manage($actor, $businessId)', 'Business Admin/System Admin management authority is required');
$contains($service, 'coveted_venue_relationship_resolve(', 'perk creation must resolve an existing venue relationship');
$contains($service, "(int)\$campaign['location_id'] !== \$locationId", 'campaign must be scoped to the exact partner location');
$contains($service, "(string)\$campaign['trigger_key'] !== 'manual'", 'Partner Perk cadence must own issuance semantics');
$contains($service, "COALESCE(vr.benefits_enabled,0) AS benefits_enabled", 'relationship benefit status must be canonical');
$contains($service, "Enable Partner benefits on this venue relationship", 'activation must require benefits-enabled relationship');
$contains($service, 'coveted_partner_perk_assert_activatable', 'activation guard is required');

// Automatic issuance must reuse the existing wallet/campaign economics.
$contains($service, "const COVETED_PARTNER_PERK_LOCK", 'Partner Perks need an independent worker lock');
$contains($service, 'SELECT GET_LOCK(?, 0)', 'worker must use a non-blocking named lock');
$contains($service, 'SELECT RELEASE_LOCK(?)', 'worker must release its named lock');
$contains($service, "pp.distribution_mode IN ('once','monthly')", 'only once/monthly perks may auto-issue');
$contains($service, "gm.membership_status='active'", 'automatic perks require active group membership');
$contains($service, "u.status='active'", 'automatic perks require active member accounts');
$contains($service, "CONCAT('partner-perk:',pp.id,':once:user:'", 'once mode needs durable member idempotency');
$contains($service, "CONCAT('partner-perk:',pp.id,':month:'", 'monthly mode needs period idempotency');
$contains($service, 'coveted_reward_issue(', 'Partner Perks must use canonical reward issuance');
$contains($service, 'Campaign distribution limit has been reached.', 'campaign pool limits must remain authoritative');
$contains($service, 'Member campaign limit has been reached.', 'campaign per-member limits must remain authoritative');
$contains($service, "'reward.partner_perk_unlocked'", 'wallet notification is required');
$contains($service, "'/benefits.php?box=ready&source=business'", 'issued perks must route to the existing Perk Wallet');

// Manual issuance is explicit and replay-safe within the issue day.
$contains($service, 'function coveted_partner_perk_issue_today', 'manual issue action is required');
$contains($service, "':manual:' . \$date . ':user:'", 'manual issue must use same-day deterministic idempotency');
$contains($service, "(string)\$perk['distribution_mode'] !== 'manual'", 'manual action must reject automatic modes');
$contains($page, "value=\"issue_today\"", 'manual issue UI action is required');
$contains($page, '>Issue Today</button>', 'manual issue control is required');
$contains($page, 'coveted_require_csrf()', 'Partner Perk mutations require CSRF protection');

// Existing lifecycle scheduler remains the only recurring worker.
$contains($worker, "require_once dirname(__DIR__) . '/app/partner_perks.php';", 'existing lifecycle worker must load Partner Perks');
$contains($worker, 'coveted_partner_perk_reconcile($limit)', 'existing lifecycle worker must reconcile Partner Perks');
$contains($worker, "!empty(\$partnerPerks['more_work_possible'])", 'Partner Perk backlog must propagate to lifecycle exit state');
$contains($worker, "(int)\$partnerPerks['failures'] > 0", 'Partner Perk failures must propagate to lifecycle failure state');
$contains($worker, 'migration not installed; this pass was skipped', 'worker must fail soft before migration is installed');

// UI is relationship-first and navigation stays additive.
$contains($page, "require_once __DIR__ . '/app/partner_perks.php';", 'Partner Perk workspace must use canonical service');
$contains($page, 'Partner benefits are currently disabled', 'workspace must explain activation boundary');
$contains($page, 'location-scoped manual Business campaign', 'workspace must explain campaign prerequisites');
$contains($loader, 'partner-perks-v1.js', 'global loader must include Partner Perk navigation');
$contains($nav, "window.location.pathname !== '/venue-relationships.php'", 'navigation enhancement must stay scoped to Venue Relationships');
$contains($nav, "link.textContent = 'Partner Perks'", 'relationship workspace must expose Partner Perks');

// Partner Perks never gain event authority or runtime schema mutation.
$contains($events, 'function coveted_event_require_system_admin(array $actor): void', 'System Admin event authority must remain canonical');
foreach (['coveted_event_create(', 'coveted_event_set_status(', 'coveted_event_update(', 'coveted_event_set_location(', 'coveted_event_assign_host('] as $mutation) {
    $missing($service, $mutation, 'Partner Perk service must not mutate event authority: ' . $mutation);
    $missing($page, $mutation, 'Partner Perk workspace must not mutate event authority: ' . $mutation);
}
foreach (['CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE TABLE'] as $ddl) {
    $missing($service, $ddl, 'service must not modify schema at runtime: ' . $ddl);
}

fwrite(STDOUT, "Partner Perks contract verified.\n");
