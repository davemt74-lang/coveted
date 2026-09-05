<?php
declare(strict_types=1);

require_once __DIR__ . '/app/groups.php';

$user = coveted_current_user();
$id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$cookieName = 'coveted_ginv_' . substr(hash('sha256', $id), 0, 16);
$incomingToken = trim((string)($_GET['token'] ?? ''));
$secureCookie = coveted_cookie_secure();

$invite = null;
if ($id !== '' && strlen($id) <= 64) {
    $stmt = coveted_db()->prepare(
        "SELECT
            gi.*,
            g.name AS group_name,
            g.public_id AS group_public_id,
            g.status AS group_status,
            u.display_name AS inviter_name
         FROM group_invitations gi
         JOIN social_groups g ON g.id = gi.group_id
         JOIN users u ON u.id = gi.inviter_user_id
         WHERE gi.public_id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $invite = $stmt->fetch() ?: null;
}

$isStayInvitation = $invite !== null && str_starts_with((string)$invite['public_id'], 'gstay_');
$isGuestPassInvitation = false;
$guestPassAvailable = true;
$stayAvailable = true;

if ($invite) {
    $passStmt = coveted_db()->prepare(
        'SELECT status FROM group_guest_passes WHERE invitation_id = ? LIMIT 1'
    );
    $passStmt->execute([(int)$invite['id']]);
    $passStatus = $passStmt->fetchColumn();
    $isGuestPassInvitation = $passStatus !== false;
    $guestPassAvailable = !$isGuestPassInvitation || $passStatus === 'reserved';

    if ($isStayInvitation) {
        $inviteeId = (int)($invite['invitee_user_id'] ?? 0);
        if ($inviteeId < 1) {
            $stayAvailable = false;
        } else {
            $membershipStmt = coveted_db()->prepare(
                "SELECT 1
                 FROM group_memberships
                 WHERE group_id = ? AND user_id = ?
                   AND membership_status = 'active' AND group_role = 'guest'
                 LIMIT 1"
            );
            $membershipStmt->execute([(int)$invite['group_id'], $inviteeId]);
            $stayAvailable = (bool)$membershipStmt->fetchColumn()
                && coveted_group_guest_has_verified_completed_attendance(
                    coveted_db(),
                    (int)$invite['group_id'],
                    $inviteeId
                );
        }
    }
}

if ($incomingToken !== '') {
    $incomingValid = $invite
        && preg_match('/^[a-f0-9]{48}$/i', $incomingToken) === 1
        && password_verify($incomingToken, (string)$invite['invite_token_hash'])
        && $invite['status'] === 'pending'
        && $invite['group_status'] === 'active'
        && $guestPassAvailable
        && $stayAvailable
        && (empty($invite['expires_at']) || strtotime((string)$invite['expires_at']) > time());

    setcookie(
        $cookieName,
        $incomingValid ? $incomingToken : '',
        [
            'expires' => $incomingValid ? time() + 3600 : time() - 3600,
            'path' => '/group-invite.php',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    coveted_redirect('/group-invite.php?id=' . rawurlencode($id));
}

$token = trim((string)($_COOKIE[$cookieName] ?? ''));
$valid = $invite
    && preg_match('/^[a-f0-9]{48}$/i', $token) === 1
    && password_verify($token, (string)$invite['invite_token_hash'])
    && $invite['status'] === 'pending'
    && $invite['group_status'] === 'active'
    && $guestPassAvailable
    && $stayAvailable
    && (empty($invite['expires_at']) || strtotime((string)$invite['expires_at']) > time());

$error = '';

if ($invite && $invite['group_status'] !== 'active') {
    $error = 'This group is not currently accepting invitations.';
} elseif ($isStayInvitation && !$stayAvailable) {
    $error = 'This Invite to Stay is no longer available.';
} elseif ($isGuestPassInvitation && !$guestPassAvailable) {
    $error = 'This Guest invitation is no longer available.';
}

if (
    $valid
    && $user
    && !empty($invite['invitee_email'])
    && strtolower((string)$invite['invitee_email']) !== strtolower((string)$user['email'])
) {
    $valid = false;
    $error = 'This invitation was issued to a different email address.';
}

$returnPath = '/group-invite.php?id=' . rawurlencode($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!$user) {
        coveted_redirect('/auth.php?action=login&return=' . rawurlencode($returnPath));
    }

    coveted_require_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        $groupPublicId = coveted_group_respond_invitation($user, $id, $token, $action);

        setcookie(
            $cookieName,
            '',
            [
                'expires' => time() - 3600,
                'path' => '/group-invite.php',
                'secure' => $secureCookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        coveted_redirect(
            $action === 'accept'
                ? '/group.php?id=' . rawurlencode($groupPublicId)
                : '/groups.php'
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted invite error: ' . $e->getMessage());
        $error = 'Unable to process this invitation.';
    }
}

$eyebrow = $isStayInvitation
    ? 'INVITE TO STAY'
    : ($isGuestPassInvitation ? 'GUEST INVITATION' : 'PRIVATE INVITATION');

coveted_page_start('Group Invitation');
?>
<section class="cv-auth-shell">
    <div class="cv-auth-copy">
        <span class="cv-eyebrow"><?= coveted_e($eyebrow) ?></span>
        <h1><?= $invite ? coveted_e($invite['group_name']) : 'Invitation unavailable' ?></h1>

        <?php if ($invite): ?>
            <?php if ($isStayInvitation): ?>
                <p><?= coveted_e($invite['inviter_name']) ?> would like you to stay with this group as a Member.</p>
            <?php elseif ($isGuestPassInvitation): ?>
                <p><?= coveted_e($invite['inviter_name']) ?> invited you to join a gathering as a Guest.</p>
            <?php else: ?>
                <p><?= coveted_e($invite['inviter_name']) ?> thinks you should be part of this group.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="cv-card cv-auth-card">
        <?php if ($error): ?>
            <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
        <?php endif; ?>

        <?php if (!$valid): ?>
            <h2>This invitation is no longer available.</h2>
            <p>It may have expired, already been used, or belong to another account.</p>
            <a class="cv-button cv-button-soft" href="/">Go to Coveted</a>
        <?php elseif (!$user): ?>
            <?php if ($isStayInvitation): ?>
                <h2>Return to <?= coveted_e($invite['group_name']) ?></h2>
                <p>Sign in to the Coveted account that attended as a Guest. This invitation cannot create a different account.</p>
                <div class="cv-action-row">
                    <a class="cv-button cv-button-primary" href="/auth.php?action=login&amp;return=<?= rawurlencode($returnPath) ?>">Sign In</a>
                </div>
            <?php else: ?>
                <h2><?= $isGuestPassInvitation ? 'Attend as a Guest' : 'Join ' . coveted_e($invite['group_name']) ?></h2>
                <p>Sign in or create a Coveted account. You will return directly to this private invitation afterward.</p>
                <div class="cv-action-row">
                    <a class="cv-button cv-button-primary" href="/auth.php?action=login&amp;return=<?= rawurlencode($returnPath) ?>">Sign In</a>
                    <a class="cv-button cv-button-soft" href="/auth.php?action=register&amp;return=<?= rawurlencode($returnPath) ?>">Create Account</a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($isStayInvitation): ?>
                <h2>Become a Member of <?= coveted_e($invite['group_name']) ?></h2>
                <p>You attended as a Guest. Accept only if you want to become a Member and continue with this group.</p>
            <?php elseif ($isGuestPassInvitation): ?>
                <h2>Attend <?= coveted_e($invite['group_name']) ?> as a Guest</h2>
                <p>Accepting keeps you a Guest. Membership is a separate choice that can be offered after verified attendance.</p>
            <?php else: ?>
                <h2>Join <?= coveted_e($invite['group_name']) ?></h2>
                <p>Coveted groups are private communities. Accept only if you know and trust the invitation.</p>
            <?php endif; ?>

            <div class="cv-action-row">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= coveted_e($id) ?>">
                    <input type="hidden" name="action" value="accept">
                    <button class="cv-button cv-button-primary" type="submit">
                        <?= $isStayInvitation ? 'Accept & Become a Member' : ($isGuestPassInvitation ? 'Accept Guest Invitation' : 'Accept Invitation') ?>
                    </button>
                </form>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= coveted_e($id) ?>">
                    <input type="hidden" name="action" value="decline">
                    <button class="cv-button cv-button-soft" type="submit">Decline</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php coveted_page_end(); ?>