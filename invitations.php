<?php
declare(strict_types=1);

require_once __DIR__ . '/app/invitation_eligibility.php';

$user = coveted_require_user();
$pdo = coveted_db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $response = coveted_event_respond_invitation(
            $user,
            (string)($_POST['invite_id'] ?? ''),
            (string)($_POST['decision'] ?? ''),
            (int)($_POST['guest_count'] ?? 0)
        );

        $message = match ($response) {
            'attending' => 'You’re in.',
            'waitlist' => 'You’re on the waitlist.',
            default => 'Invitation declined.',
        };
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted invitation response error: ' . $e->getMessage());
        $error = 'Unable to update that invitation right now.';
    }
}

$stmt = $pdo->prepare(
    "SELECT
        ei.public_id,
        ei.invite_type,
        ei.status,
        e.title,
        e.description,
        e.event_type,
        e.audience,
        e.timezone,
        e.starts_at,
        e.plus_one_allowed,
        e.location_visibility,
        e.status AS event_status,
        g.name AS group_name,
        er.response AS rsvp_response,
        er.guest_count
     FROM event_invitations ei
     JOIN events e ON e.id = ei.event_id
     JOIN social_groups g ON g.id = e.group_id
     LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ei.user_id
     WHERE ei.user_id = ?
       AND e.status <> 'draft'
     ORDER BY FIELD(ei.status, 'pending', 'accepted', 'declined', 'expired', 'revoked'), e.starts_at ASC
     LIMIT 100"
);
$stmt->execute([(int)$user['id']]);
$invitations = $stmt->fetchAll();

$now = time();
$pendingCount = 0;
$acceptedCount = 0;
$waitlistCount = 0;
foreach ($invitations as $invite) {
    $future = coveted_utc_datetime((string)$invite['starts_at'])->getTimestamp() > $now;
    if ($invite['status'] === 'pending' && $invite['event_status'] === 'published' && $future) {
        $pendingCount++;
    }
    if ($invite['status'] === 'accepted' || $invite['rsvp_response'] === 'attending') {
        $acceptedCount++;
    }
    if ($invite['rsvp_response'] === 'waitlist') {
        $waitlistCount++;
    }
}

$nextExperience = coveted_next_experience_for_member($user, 4);

$view = strtolower(trim((string)($_GET['view'] ?? ($pendingCount > 0 ? 'pending' : 'history'))));
if (!in_array($view, ['pending', 'history'], true)) {
    $view = $pendingCount > 0 ? 'pending' : 'history';
}

$visibleInvitations = array_values(array_filter(
    $invitations,
    static function (array $invite) use ($view, $now): bool {
        $future = coveted_utc_datetime((string)$invite['starts_at'])->getTimestamp() > $now;
        $isPending = $invite['status'] === 'pending'
            && $invite['event_status'] === 'published'
            && $future;
        return $view === 'pending' ? $isPending : !$isPending;
    }
));
usort(
    $visibleInvitations,
    static fn(array $a, array $b): int => $view === 'pending'
        ? strcmp((string)$a['starts_at'], (string)$b['starts_at'])
        : strcmp((string)$b['starts_at'], (string)$a['starts_at'])
);

coveted_page_start('Invitations', 'Invitations');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">INVITATIONS</span>
    <h1>Someone wants you there.</h1>
    <p>Decide before you arrive. Once the gathering starts, the app gets out of the way.</p>
</section>

<?php if ($message !== ''): ?>
    <div class="cv-alert"><?= coveted_e($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>

<section class="cv-stat-grid" aria-label="Invitation summary">
    <div class="cv-card cv-stat">
        <strong><?= $pendingCount ?></strong>
        <span>Need a response</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= $acceptedCount ?></strong>
        <span>Accepted</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= $waitlistCount ?></strong>
        <span>Waitlist</span>
    </div>
</section>

<?php if ($nextExperience): ?>
    <section class="cv-card">
        <span class="cv-eyebrow">YOUR NEXT EXPERIENCE</span>
        <h2>Private context, visible only to you.</h2>
        <p>Your own attendance, “Would you do this again?” answer and Mutual Reconnect history can help you understand where Coveted has useful context. Hosts see participation history and group-level aggregates—not your individual answers or matches.</p>

        <div class="cv-role-request-list">
            <?php foreach ($nextExperience as $experience): ?>
                <div class="cv-role-request">
                    <div>
                        <strong><?= coveted_e((string)$experience['group_name']) ?></strong>
                        <span><?= coveted_e((string)$experience['state_message']) ?></span>
                        <div class="cv-tag-row">
                            <span class="cv-pill"><?= coveted_e((string)$experience['state_label']) ?></span>
                            <span class="cv-pill"><?= (int)$experience['verified_attendance_count'] ?> verified gathering<?= (int)$experience['verified_attendance_count'] === 1 ? '' : 's' ?></span>
                            <?php if ((int)$experience['mystery_attendance_count'] > 0): ?>
                                <span class="cv-pill"><?= (int)$experience['mystery_attendance_count'] ?> mystery</span>
                            <?php endif; ?>
                            <?php if ((int)$experience['mutual_reconnect_count'] > 0): ?>
                                <span class="cv-pill"><?= (int)$experience['mutual_reconnect_count'] ?> mutual reconnect<?= (int)$experience['mutual_reconnect_count'] === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                            <?php if (!empty($experience['mystery_ready'])): ?>
                                <span class="cv-pill">Mystery-ready</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="cv-button cv-button-soft" href="/group.php?group=<?= coveted_e((string)$experience['group_public_id']) ?>">Group</a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<nav class="cv-tab-row" aria-label="Invitation views">
    <a class="cv-tab <?= $view === 'pending' ? 'is-active' : '' ?>" href="/invitations.php?view=pending">Needs response<?= $pendingCount > 0 ? ' · ' . $pendingCount : '' ?></a>
    <a class="cv-tab <?= $view === 'history' ? 'is-active' : '' ?>" href="/invitations.php?view=history">History</a>
</nav>

<section class="cv-list">
    <?php if (!$visibleInvitations): ?>
        <div class="cv-card cv-empty">
            <h2><?= $view === 'pending' ? 'You’re caught up.' : 'No invitation history yet.' ?></h2>
            <p><?= $view === 'pending'
                ? 'There are no invitations waiting for your response.'
                : 'Accepted, declined, closed and past invitations will stay here for context.' ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($visibleInvitations as $invite): ?>
        <?php
        $future = coveted_utc_datetime((string)$invite['starts_at'])->getTimestamp() > $now;
        $canRespond = $invite['status'] === 'pending'
            && $invite['event_status'] === 'published'
            && $future;
        $description = trim((string)($invite['description'] ?? ''));
        ?>
        <article class="cv-card cv-event-row">
            <div class="cv-event-date">
                <strong><?= coveted_e(coveted_event_format($invite, 'M')) ?></strong>
                <span><?= coveted_e(coveted_event_format($invite, 'j')) ?></span>
            </div>

            <div class="cv-event-copy">
                <span class="cv-kicker">
                    <?= coveted_e($invite['group_name']) ?> · <?= coveted_e(str_replace('_', ' ', (string)$invite['event_type'])) ?>
                </span>
                <h2><?= coveted_e($invite['title']) ?></h2>
                <p><?= coveted_e(coveted_event_format($invite, 'l, F j · g:i A')) ?></p>

                <?php if ($description !== ''): ?>
                    <p><?= coveted_e(mb_strimwidth($description, 0, 260, '…')) ?></p>
                <?php endif; ?>

                <div class="cv-tag-row">
                    <?php if ($invite['event_type'] === 'mystery' || $invite['location_visibility'] !== 'immediate'): ?>
                        <span class="cv-pill">Location revealed later</span>
                    <?php endif; ?>
                    <?php if ($invite['audience'] === 'invitation_only'): ?>
                        <span class="cv-pill">Invitation only</span>
                    <?php endif; ?>
                    <?php if ((int)($invite['guest_count'] ?? 0) === 1 && $invite['rsvp_response'] === 'attending'): ?>
                        <span class="cv-pill">+1 included</span>
                    <?php endif; ?>
                    <?php if ($invite['rsvp_response'] === 'waitlist'): ?>
                        <span class="cv-pill">Waitlist</span>
                    <?php elseif ($invite['event_status'] === 'cancelled'): ?>
                        <span class="cv-pill">Cancelled</span>
                    <?php elseif (!$future && $invite['status'] === 'pending'): ?>
                        <span class="cv-pill">Closed</span>
                    <?php elseif (!$canRespond && $invite['status'] !== 'pending'): ?>
                        <span class="cv-pill"><?= coveted_e(ucfirst((string)$invite['status'])) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($canRespond): ?>
                    <form class="cv-action-row" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="invite_id" value="<?= coveted_e($invite['public_id']) ?>">

                        <?php if ((bool)$invite['plus_one_allowed']): ?>
                            <label class="cv-inline-form">
                                <span class="cv-kicker">RSVP FOR</span>
                                <select name="guest_count">
                                    <option value="0">Just me</option>
                                    <option value="1">Me + one guest</option>
                                </select>
                            </label>
                        <?php else: ?>
                            <input type="hidden" name="guest_count" value="0">
                        <?php endif; ?>

                        <button class="cv-button cv-button-primary" name="decision" value="accepted" type="submit">I’m attending</button>
                        <button class="cv-button cv-button-soft" name="decision" value="declined" type="submit">Decline</button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_page_end(); ?>
