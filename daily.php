<?php
declare(strict_types=1);

require_once __DIR__ . '/app/daily_events.php';

$user = coveted_require_user();
$error = '';
$notice = trim((string)($_SESSION['daily_event_notice'] ?? ''));
unset($_SESSION['daily_event_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    $dailyRef = trim((string)($_POST['daily_ref'] ?? ''));
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($dailyRef === '' || strlen($dailyRef) > 64) {
            throw new InvalidArgumentException('Daily Event not found.');
        }
        if ($action === 'rsvp_attending' || $action === 'rsvp_declined') {
            $response = coveted_daily_event_set_rsvp(
                $user,
                $dailyRef,
                $action === 'rsvp_attending' ? 'attending' : 'declined'
            );
            $_SESSION['daily_event_notice'] = match ($response) {
                'attending' => 'You’re in. Your place is reserved.',
                'waitlist' => 'The event is full, so you were added to the waitlist.',
                default => 'Your RSVP was updated.',
            };
            coveted_redirect('/daily.php');
        }
        if ($action === 'checkin') {
            $result = coveted_daily_event_member_checkin(
                $user,
                $dailyRef,
                (string)($_POST['claim_code'] ?? '')
            );
            $_SESSION['daily_event_notice'] = !empty($result['already_checked_in'])
                ? 'You’re already checked in for this Daily Event.'
                : ((bool)$result['reward_unlocked']
                    ? 'Check-in verified. The group attendance reward is unlocked.'
                    : 'Check-in verified. Your attendance counts toward the group reward.');
            coveted_redirect('/daily.php');
        }
        throw new InvalidArgumentException('Unsupported Daily Event action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Daily Event member action failed: ' . $e->getMessage());
        $error = 'Unable to update that Daily Event right now.';
    }
}

try {
    $dailyEvents = coveted_daily_event_member_feed($user, 14);
} catch (Throwable $e) {
    error_log('Daily Event member feed unavailable: ' . $e->getMessage());
    $dailyEvents = [];
    $error = $error !== '' ? $error : 'Daily Events are not available yet. Coveted Admin may still need to apply the Daily Events database upgrade.';
}

$now = time();
$today = [];
$upcoming = [];
$recent = [];
foreach ($dailyEvents as $event) {
    $tz = coveted_timezone((string)$event['timezone']);
    $eventLocalDate = coveted_utc_datetime((string)$event['starts_at'])->setTimezone($tz)->format('Y-m-d');
    $todayLocal = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    if ($eventLocalDate === $todayLocal && $event['event_status'] !== 'completed') {
        $today[] = $event;
    } elseif (strtotime((string)$event['starts_at']) > $now) {
        $upcoming[] = $event;
    } else {
        $recent[] = $event;
    }
}

$renderCard = static function (array $event): void {
    $verified = (int)$event['verified_attendance'];
    $threshold = (int)$event['attendance_threshold'];
    $loyaltyPoints = (int)$event['loyalty_points'];
    $progress = $threshold > 0 ? min(100, (int)round(($verified / $threshold) * 100)) : 0;
    $address = implode(', ', array_values(array_filter([
        trim((string)($event['address1'] ?? '')),
        trim((string)($event['city'] ?? '')),
        trim((string)($event['region'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));
    $rewardValue = trim((string)($event['value_text'] ?? ''));
    if ($rewardValue === '' && $event['value_amount'] !== null) {
        $rewardValue = '$' . number_format((float)$event['value_amount'], 2);
    }
    ?>
    <article class="cv-card cv-copy-card">
        <div class="cv-section-head">
            <div>
                <span class="cv-kicker">DAILY EVENT · <?= coveted_e((string)$event['business_name']) ?></span>
                <h3><?= coveted_e((string)$event['title']) ?></h3>
                <p><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e(coveted_event_format($event, 'D, M j · g:i A')) ?></p>
            </div>
            <?php if (!empty($event['member_reward_issued'])): ?>
                <span class="cv-pill">Reward earned</span>
            <?php elseif (!empty($event['reward_unlocked'])): ?>
                <span class="cv-pill">Group reward unlocked</span>
            <?php elseif (($event['rsvp_response'] ?? '') === 'attending'): ?>
                <span class="cv-pill">Attending</span>
            <?php endif; ?>
        </div>

        <p><?= coveted_e((string)($event['description'] ?? '')) ?></p>
        <dl class="cv-member-detail-list">
            <div><dt>Partner</dt><dd><?= coveted_e((string)$event['business_name']) ?></dd></div>
            <div><dt>Location</dt><dd><?= coveted_e((string)$event['location_name']) ?><?= $address !== '' ? ' · ' . coveted_e($address) : '' ?></dd></div>
            <div><dt>Reward</dt><dd><?= coveted_e((string)$event['reward_title']) ?><?= $rewardValue !== '' ? ' · ' . coveted_e($rewardValue) : '' ?></dd></div>
            <div><dt>Loyalty</dt><dd><?= $loyaltyPoints > 0 ? '+' . $loyaltyPoints . ' private points after verified attendance + completion' : 'No Loyalty points for this Daily Event' ?></dd></div>
            <div><dt>Going</dt><dd><?= (int)$event['attending_rsvps'] ?> RSVP<?= (int)$event['attending_rsvps'] === 1 ? '' : 's' ?></dd></div>
        </dl>

        <div class="cv-admin-section-gap">
            <div class="cv-section-head">
                <div><strong>Group attendance reward</strong><p><?= $verified ?> of <?= $threshold ?> verified attendees</p></div>
                <strong><?= $progress ?>%</strong>
            </div>
            <div class="cv-progress"><span style="width:<?= $progress ?>%"></span></div>
            <?php if (!empty($event['reward_unlocked'])): ?>
                <p class="cv-muted">Threshold reached. Every verified attendee receives the linked group reward through the Coveted Wallet.</p>
            <?php else: ?>
                <p class="cv-muted"><?= (int)$event['threshold_remaining'] ?> more verified attendee<?= (int)$event['threshold_remaining'] === 1 ? '' : 's' ?> needed to unlock the group reward.</p>
            <?php endif; ?>
        </div>

        <div class="cv-event-card-actions">
            <a class="cv-member-text-link" href="/event.php?event=<?= coveted_e(rawurlencode((string)$event['event_ref'])) ?>">View event →</a>
            <?php if ($event['event_status'] === 'published' && strtotime((string)$event['starts_at']) > time()): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="daily_ref" value="<?= coveted_e((string)$event['public_id']) ?>">
                    <input type="hidden" name="action" value="<?= ($event['rsvp_response'] ?? '') === 'attending' ? 'rsvp_declined' : 'rsvp_attending' ?>">
                    <button class="cv-button <?= ($event['rsvp_response'] ?? '') === 'attending' ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
                        <?= ($event['rsvp_response'] ?? '') === 'attending' ? 'Can’t attend' : 'I’ll attend' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($event['checkin_open'])): ?>
            <form class="cv-card cv-copy-card cv-admin-section-gap" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="checkin">
                <input type="hidden" name="daily_ref" value="<?= coveted_e((string)$event['public_id']) ?>">
                <span class="cv-kicker">AT THE PARTNER LOCATION</span>
                <h4>Verify your attendance</h4>
                <p>Enter the location or employee claim code at <?= coveted_e((string)$event['location_name']) ?>. The code verifies this real-world visit; it does not expose your private Loyalty balance.</p>
                <label>Partner check-in code
                    <input name="claim_code" inputmode="text" maxlength="10" pattern="[A-Za-z0-9]{5,10}" required autocomplete="off">
                </label>
                <button class="cv-button cv-button-primary" type="submit">Check in</button>
            </form>
        <?php elseif (in_array((string)($event['attendance_status'] ?? ''), ['checked_in','attended','left_early'], true)): ?>
            <div class="cv-alert cv-admin-section-gap">Attendance verified. After Admin completes the event, this visit is worth exactly <?= $loyaltyPoints ?> private Coveted Loyalty point<?= $loyaltyPoints === 1 ? '' : 's' ?> and continues to count toward your group status, milestones and lifetime travel-ready points history.</div>
        <?php endif; ?>
    </article>
    <?php
};

coveted_page_start('Daily Events', 'Daily');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">DAILY OPPORTUNITIES</span>
    <h1>Show up together. Unlock something together.</h1>
    <p>Partnered events at real local businesses. Choose what you want to attend, verify your visit at the location, earn the event’s private Loyalty value, and help your group unlock shared rewards.</p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-stat-grid" aria-label="Daily Event overview">
    <div class="cv-card cv-stat"><strong><?= count($today) ?></strong><span>Today</span></div>
    <div class="cv-card cv-stat"><strong><?= count($upcoming) ?></strong><span>Next 14 days</span></div>
    <div class="cv-card cv-stat"><strong><?= count(array_filter($dailyEvents, static fn(array $row): bool => !empty($row['member_reward_issued']))) ?></strong><span>Group rewards earned</span></div>
    <div class="cv-card cv-stat"><strong><?= count(array_filter($dailyEvents, static fn(array $row): bool => in_array((string)($row['attendance_status'] ?? ''), ['checked_in','attended','left_early'], true))) ?></strong><span>Verified visits</span></div>
</section>

<div class="cv-section-head cv-admin-section-gap"><div><span class="cv-eyebrow">TODAY</span><h2>Available now.</h2></div></div>
<section class="cv-stack">
    <?php if (!$today): ?><div class="cv-card cv-empty"><h3>No partnered Daily Events today.</h3><p>Upcoming opportunities are listed below.</p></div><?php endif; ?>
    <?php foreach ($today as $event) $renderCard($event); ?>
</section>

<div class="cv-section-head cv-admin-section-gap"><div><span class="cv-eyebrow">UPCOMING</span><h2>Choose what fits.</h2></div></div>
<section class="cv-stack">
    <?php if (!$upcoming): ?><div class="cv-card cv-empty"><p>No additional Daily Events are scheduled in the next 14 days.</p></div><?php endif; ?>
    <?php foreach ($upcoming as $event) $renderCard($event); ?>
</section>

<?php if ($recent): ?>
<div class="cv-section-head cv-admin-section-gap"><div><span class="cv-eyebrow">RECENT</span><h2>Just happened.</h2></div></div>
<section class="cv-stack"><?php foreach ($recent as $event) $renderCard($event); ?></section>
<?php endif; ?>

<?php coveted_page_end(); ?>
