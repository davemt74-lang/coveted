<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/daily_events.php';

$admin = coveted_require_system_admin();
$error = '';
$notice = trim((string)($_SESSION['admin_daily_event_notice'] ?? ''));
unset($_SESSION['admin_daily_event_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'create') {
            $created = coveted_daily_event_create($admin, $_POST);
            $_SESSION['admin_daily_event_notice'] = 'Daily Event created. Members can see it when the underlying event is published.';
            coveted_redirect('/admin/daily-events.php?created=' . rawurlencode((string)$created['public_id']));
        }
        if ($action === 'set_status') {
            coveted_daily_event_set_status(
                $admin,
                (string)($_POST['daily_ref'] ?? ''),
                (string)($_POST['status'] ?? '')
            );
            $_SESSION['admin_daily_event_notice'] = 'Daily Event opportunity status updated.';
            coveted_redirect('/admin/daily-events.php');
        }
        throw new InvalidArgumentException('Unsupported Daily Event action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Admin Daily Event action failed: ' . $e->getMessage());
        $error = 'Unable to update Daily Events right now.';
    }
}

try {
    $relationships = coveted_daily_event_relationship_options();
    $campaigns = coveted_daily_event_reward_campaign_options();
    $rows = coveted_daily_event_admin_rows();
} catch (Throwable $e) {
    error_log('Admin Daily Events load failed: ' . $e->getMessage());
    $relationships = [];
    $campaigns = [];
    $rows = [];
    $error = $error !== '' ? $error : 'Daily Events are unavailable. Apply the Daily Events database migration first.';
}

$format = static function (array $row, string $field): string {
    if (empty($row[$field])) return '—';
    return coveted_utc_datetime((string)$row[$field])
        ->setTimezone(coveted_timezone((string)$row['timezone']))
        ->format('M j, Y · g:i A');
};

coveted_admin_ui_start($admin, 'daily-events', 'Daily Events');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">PARTNERED DAILY OPPORTUNITIES</span>
    <h1>Turn partner locations into optional member experiences.</h1>
    <p>System Admin creates the event, assigns the exact Business Partner location, chooses a dedicated group reward, and sets the verified-attendance threshold. Partners never gain event-creation authority.</p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-stat-grid">
    <div class="cv-card cv-stat"><strong><?= count($rows) ?></strong><span>Daily Events</span></div>
    <div class="cv-card cv-stat"><strong><?= count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active' && in_array($row['event_status'], ['published','closed'], true))) ?></strong><span>Live opportunities</span></div>
    <div class="cv-card cv-stat"><strong><?= array_sum(array_map(static fn(array $row): int => (int)$row['verified_attendance'], $rows)) ?></strong><span>Verified attendance</span></div>
    <div class="cv-card cv-stat"><strong><?= array_sum(array_map(static fn(array $row): int => (int)$row['rewards_issued'], $rows)) ?></strong><span>Group rewards issued</span></div>
</section>

<section class="cv-card cv-copy-card cv-admin-section-gap">
    <div class="cv-section-head">
        <div><span class="cv-kicker">CREATE DAILY EVENT</span><h2>Event + partner + threshold reward.</h2></div>
        <a class="cv-button cv-button-soft" href="/admin/benefit-programs.php">Create reward campaign</a>
    </div>
    <p>Only benefit-enabled venue relationships are available. The selected Business campaign must be active, location-code redeemable, manual-triggered, dedicated to this event, and large enough to reward every possible attendee.</p>

    <?php if (!$relationships): ?>
        <div class="cv-alert">No eligible partner locations are ready. Create an active group/location relationship, enable benefits, and make sure the Business location has an active claim code.</div>
    <?php elseif (!$campaigns): ?>
        <div class="cv-alert">No eligible dedicated Business reward campaigns are ready. Create an active Business-owned manual campaign at the partner location with a location-code reward.</div>
    <?php else: ?>
    <form method="post" class="cv-stack">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <label>Group × partner location
            <select name="relationship_id" required>
                <option value="">Choose relationship</option>
                <?php foreach ($relationships as $relationship): ?>
                    <option value="<?= (int)$relationship['relationship_id'] ?>">
                        <?= coveted_e((string)$relationship['group_name']) ?> · <?= coveted_e((string)$relationship['business_name']) ?> / <?= coveted_e((string)$relationship['location_name']) ?><?= !empty($relationship['location_city']) ? ' · ' . coveted_e((string)$relationship['location_city']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Dedicated group reward campaign
            <select name="reward_campaign_id" required>
                <option value="">Choose campaign</option>
                <?php foreach ($campaigns as $campaign): ?>
                    <option value="<?= (int)$campaign['campaign_id'] ?>">
                        <?= coveted_e((string)$campaign['business_name']) ?> / <?= coveted_e((string)$campaign['location_name']) ?> · <?= coveted_e((string)$campaign['campaign_title']) ?> → <?= coveted_e((string)$campaign['reward_title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="cv-two-column">
            <label>Event title<input name="title" maxlength="190" required placeholder="Tuesday patio meetup"></label>
            <label>Publish state
                <select name="status"><option value="draft">Draft</option><option value="published">Published</option></select>
            </label>
        </div>
        <label>Description<textarea name="description" maxlength="5000" rows="4" placeholder="What members should know about this optional partner event."></textarea></label>

        <div class="cv-two-column">
            <label>Starts at partner local time<input type="datetime-local" name="starts_local" required></label>
            <label>Ends at partner local time<input type="datetime-local" name="ends_local" required></label>
        </div>
        <div class="cv-two-column">
            <label>Capacity<input type="number" name="capacity" min="1" step="1" placeholder="Uses location capacity when blank"></label>
            <label>Verified attendance needed to unlock reward<input type="number" name="attendance_threshold" min="1" step="1" required value="10"></label>
        </div>

        <div class="cv-alert">
            Members opt in themselves. At the event they enter the Business Partner’s existing location/employee claim code. Only that verified attendance counts toward the threshold. The same completed-event attendance later earns normal private Coveted Loyalty points.
        </div>
        <button class="cv-button cv-button-primary" type="submit">Create Daily Event</button>
    </form>
    <?php endif; ?>
</section>

<div class="cv-section-head cv-admin-section-gap"><div><span class="cv-eyebrow">EVENTS</span><h2>Partner opportunity performance.</h2></div></div>
<section class="cv-stack">
    <?php if (!$rows): ?><div class="cv-card cv-empty"><p>No Daily Events have been created yet.</p></div><?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <?php
        $verified = (int)$row['verified_attendance'];
        $threshold = (int)$row['attendance_threshold'];
        $progress = $threshold > 0 ? min(100, (int)round(($verified / $threshold) * 100)) : 0;
        ?>
        <article class="cv-card cv-copy-card">
            <div class="cv-section-head">
                <div>
                    <span class="cv-kicker"><?= coveted_e(strtoupper((string)$row['business_name'])) ?> · <?= coveted_e((string)$row['location_name']) ?></span>
                    <h3><?= coveted_e((string)$row['title']) ?></h3>
                    <p><?= coveted_e((string)$row['group_name']) ?> · <?= coveted_e($format($row, 'starts_at')) ?> · Event <?= coveted_e((string)$row['event_status']) ?></p>
                </div>
                <span class="cv-pill"><?= coveted_e((string)$row['status']) ?></span>
            </div>
            <div class="cv-stat-grid">
                <div class="cv-card cv-stat"><strong><?= (int)$row['attending_rsvps'] ?></strong><span>Attending RSVP</span></div>
                <div class="cv-card cv-stat"><strong><?= $verified ?></strong><span>Verified</span></div>
                <div class="cv-card cv-stat"><strong><?= $threshold ?></strong><span>Unlock threshold</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$row['rewards_issued'] ?></strong><span>Rewards issued</span></div>
            </div>
            <div class="cv-admin-section-gap">
                <div class="cv-section-head"><strong>Group reward progress</strong><span><?= $progress ?>%</span></div>
                <div class="cv-progress"><span style="width:<?= $progress ?>%"></span></div>
                <p class="cv-muted"><?= coveted_e((string)$row['reward_title']) ?> · <?= !empty($row['reward_unlocked_at']) ? 'Unlocked' : max(0,$threshold-$verified) . ' more verified attendee' . (max(0,$threshold-$verified) === 1 ? '' : 's') . ' needed' ?></p>
            </div>
            <div class="cv-event-card-actions">
                <a class="cv-member-text-link" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$row['event_ref'])) ?>">Manage event →</a>
                <a class="cv-member-text-link" href="/event.php?event=<?= coveted_e(rawurlencode((string)$row['event_ref'])) ?>">Member view →</a>
                <?php if ($row['status'] !== 'archived'): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="daily_ref" value="<?= coveted_e((string)$row['public_id']) ?>">
                    <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'paused' : 'active' ?>">
                    <button class="cv-button cv-button-soft" type="submit"><?= $row['status'] === 'active' ? 'Pause opportunity' : 'Resume opportunity' ?></button>
                </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<?php coveted_admin_ui_end(); ?>
