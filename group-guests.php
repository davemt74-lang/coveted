<?php
declare(strict_types=1);

require_once __DIR__ . '/app/guest_continuity.php';

$user = coveted_require_user();
$ref = trim((string)($_GET['group'] ?? $_POST['group'] ?? ''));
$group = $ref !== '' ? coveted_group_by_ref($ref) : null;

if (!$group) {
    http_response_code(404);
    exit('Group not found.');
}
if (!coveted_group_can_admin($group, $user)) {
    http_response_code(403);
    exit('Group Admin access is required.');
}

$error = '';
$notice = '';
$inviteLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'invite_to_stay') {
            throw new InvalidArgumentException('Unsupported guest continuity action.');
        }

        $invitation = coveted_group_create_stay_invitation(
            $group,
            $user,
            (int)($_POST['user_id'] ?? 0)
        );
        $inviteLink = $invitation['url'];
        $notice = 'Invite to Stay created. Share the private link with that Guest.';
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted guest continuity error: ' . $e->getMessage());
        $error = 'Unable to complete that request.';
    }
}

$candidates = coveted_group_guest_continuity_candidates($group, $user);
$summary = coveted_group_guest_continuity_summary($group, $user);

coveted_page_start('Guest Continuity', 'Groups');
?>
<section class="cv-page-heading">
    <a class="cv-text-link" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=manage">← Back to <?= coveted_e($group['name']) ?></a>
    <span class="cv-eyebrow">GUEST CONTINUITY</span>
    <h1>Invite the right people to stay.</h1>
    <p>A Guest becomes eligible only after verified attendance at a completed gathering. Membership changes only after that Guest explicitly accepts an Invite to Stay.</p>
</section>

<?php if ($error): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>
<?php if ($notice): ?>
    <div class="cv-alert"><?= coveted_e($notice) ?></div>
<?php endif; ?>

<?php if ($inviteLink): ?>
    <div class="cv-card cv-copy-card">
        <span class="cv-eyebrow">PRIVATE INVITE TO STAY</span>
        <strong>Share this link only with the Guest you selected.</strong>
        <p class="cv-code-link"><?= coveted_e($inviteLink) ?></p>
        <p>The invitation expires in 14 days. The Guest remains a Guest unless they accept it.</p>
    </div>
<?php endif; ?>

<section class="cv-stat-grid cv-home-stats" aria-label="Guest continuity summary">
    <div class="cv-card cv-stat">
        <strong><?= (int)$summary['guest_passes_used'] ?></strong>
        <span>Guest passes used</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= (int)$summary['eligible_guests'] ?></strong>
        <span>Eligible Guests</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= (int)$summary['pending_stay_invites'] ?></strong>
        <span>Invites pending</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= (int)$summary['member_conversions'] ?></strong>
        <span>Became Members</span>
    </div>
</section>

<div class="cv-two-column">
    <section class="cv-stack">
        <div class="cv-section-head">
            <div>
                <span class="cv-eyebrow">ELIGIBLE GUESTS</span>
                <h2>People who actually showed up</h2>
            </div>
        </div>

        <?php if (!$candidates): ?>
            <article class="cv-card cv-empty">
                <h2>No Guests are eligible right now.</h2>
                <p>After an active Guest has verified attendance at a completed gathering, they can appear here.</p>
            </article>
        <?php else: ?>
            <?php foreach ($candidates as $candidate): ?>
                <article class="cv-card cv-member-row">
                    <div>
                        <strong><?= coveted_e($candidate['display_name']) ?></strong>
                        <span>
                            <?= (int)$candidate['verified_gatherings'] ?> verified gathering<?= (int)$candidate['verified_gatherings'] === 1 ? '' : 's' ?>
                            <?php if (!empty($candidate['last_attended_at'])): ?>
                                · Last attended <?= coveted_e(coveted_utc_datetime((string)$candidate['last_attended_at'])->format('M j, Y')) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="cv-member-actions">
                        <?php if (!empty($candidate['stay_invite_pending'])): ?>
                            <span class="cv-pill">Invite pending</span>
                        <?php else: ?>
                            <form method="post" class="cv-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="group" value="<?= coveted_e($group['public_id']) ?>">
                                <input type="hidden" name="action" value="invite_to_stay">
                                <input type="hidden" name="user_id" value="<?= (int)$candidate['user_id'] ?>">
                                <button class="cv-button cv-button-primary" type="submit">Invite to Stay</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <aside class="cv-stack">
        <article class="cv-card cv-copy-card">
            <span class="cv-eyebrow">CONSENT FIRST</span>
            <h2>Guests are not auto-promoted.</h2>
            <p>Attendance makes a Guest eligible for an invitation. It does not make them a Member. Accepting the private Invite to Stay is the membership decision.</p>
        </article>

        <article class="cv-card cv-copy-card">
            <span class="cv-eyebrow">CONVERSION</span>
            <h2><?= coveted_e(number_format((float)$summary['conversion_rate'], 1)) ?>%</h2>
            <p>Accepted Invite-to-Stay conversions compared with Guest Passes used. This is an aggregate group outcome, not a score attached to any person.</p>
        </article>
    </aside>
</div>

<?php coveted_page_end(); ?>
