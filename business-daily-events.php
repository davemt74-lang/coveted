<?php
declare(strict_types=1);

require_once __DIR__ . '/app/daily_events.php';

$user = coveted_require_user();
$error = '';
$businessRef = trim((string)($_GET['business'] ?? ''));

try {
    $business = coveted_business_resolve_context($user, $businessRef);
    if (!$business) {
        throw new InvalidArgumentException('No Business Partner workspace is available to this account.');
    }
    $rows = coveted_daily_event_business_rows($user, (int)$business['id']);
} catch (InvalidArgumentException $e) {
    $business = null;
    $rows = [];
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Business Daily Events unavailable: ' . $e->getMessage());
    $business = null;
    $rows = [];
    $error = 'Business Daily Events are unavailable right now.';
}

$format = static function (array $row, string $field): string {
    if (empty($row[$field])) return '—';
    return coveted_utc_datetime((string)$row[$field])
        ->setTimezone(coveted_timezone((string)$row['timezone']))
        ->format('M j, Y · g:i A');
};

coveted_page_start('Partner Daily Events');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">BUSINESS PARTNER · DAILY EVENTS</span>
    <h1><?= $business ? coveted_e((string)$business['name']) : 'Partner Daily Events' ?></h1>
    <p>See the Coveted Daily Events assigned to your locations, check-in readiness, verified group attendance and sponsored reward distribution. Event creation and configuration remain with Coveted System Admin.</p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if ($business): ?>
<div class="cv-section-head">
    <div><span class="cv-eyebrow">PARTNER TOOLS</span><h2>Location readiness.</h2></div>
    <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode((string)$business['public_id'])) ?>">Manage Business & claim codes</a>
</div>

<section class="cv-stat-grid">
    <div class="cv-card cv-stat"><strong><?= count($rows) ?></strong><span>Assigned Daily Events</span></div>
    <div class="cv-card cv-stat"><strong><?= array_sum(array_map(static fn(array $row): int => (int)$row['attending_rsvps'], $rows)) ?></strong><span>Attending RSVP</span></div>
    <div class="cv-card cv-stat"><strong><?= array_sum(array_map(static fn(array $row): int => (int)$row['verified_attendance'], $rows)) ?></strong><span>Verified attendance</span></div>
    <div class="cv-card cv-stat"><strong><?= array_sum(array_map(static fn(array $row): int => (int)$row['rewards_issued'], $rows)) ?></strong><span>Group rewards issued</span></div>
</section>

<section class="cv-stack cv-admin-section-gap">
    <?php if (!$rows): ?><div class="cv-card cv-empty"><h3>No Daily Events assigned yet.</h3><p>Coveted Admin can assign a benefit-enabled partner location to a Daily Event when there is an eligible reward campaign and active claim code.</p></div><?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <?php
        $verified = (int)$row['verified_attendance'];
        $threshold = (int)$row['attendance_threshold'];
        $progress = $threshold > 0 ? min(100, (int)round(($verified / $threshold) * 100)) : 0;
        ?>
        <article class="cv-card cv-copy-card">
            <div class="cv-section-head">
                <div>
                    <span class="cv-kicker"><?= coveted_e((string)$row['location_name']) ?> · <?= coveted_e((string)$row['group_name']) ?></span>
                    <h3><?= coveted_e((string)$row['title']) ?></h3>
                    <p><?= coveted_e($format($row,'starts_at')) ?></p>
                </div>
                <span class="cv-pill"><?= coveted_e((string)$row['event_status']) ?></span>
            </div>
            <div class="cv-stat-grid">
                <div class="cv-card cv-stat"><strong><?= (int)$row['attending_rsvps'] ?></strong><span>RSVP</span></div>
                <div class="cv-card cv-stat"><strong><?= $verified ?></strong><span>Verified</span></div>
                <div class="cv-card cv-stat"><strong><?= $threshold ?></strong><span>Threshold</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$row['rewards_issued'] ?></strong><span>Rewards issued</span></div>
            </div>
            <div class="cv-admin-section-gap">
                <div class="cv-section-head"><strong>Group reward progress</strong><span><?= $progress ?>%</span></div>
                <div class="cv-progress"><span style="width:<?= $progress ?>%"></span></div>
                <p class="cv-muted"><?= coveted_e((string)$row['reward_title']) ?> · <?= !empty($row['reward_unlocked_at']) ? 'Unlocked' : max(0,$threshold-$verified) . ' more verified attendee' . (max(0,$threshold-$verified) === 1 ? '' : 's') . ' needed' ?></p>
            </div>
            <?php if ((int)$row['active_checkin_codes'] < 1): ?>
                <div class="cv-alert cv-alert-error">This location no longer has an active claim code. Member code check-in will fail until the Business Partner restores an active location or employee code.</div>
            <?php else: ?>
                <div class="cv-alert">Check-in ready: <?= (int)$row['active_checkin_codes'] ?> active claim-code path<?= (int)$row['active_checkin_codes'] === 1 ? '' : 's' ?> can verify members at this location.</div>
            <?php endif; ?>
            <p class="cv-muted">Partner reporting is aggregate. Member identities, private Loyalty balances and person-level scoring are not exposed here.</p>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php coveted_page_end(); ?>
