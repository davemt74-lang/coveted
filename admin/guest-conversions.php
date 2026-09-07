<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/guest_member_conversion.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/system-preview.php?view=people');
}

$error = '';
$notice = trim((string)($_SESSION['guest_conversion_notice'] ?? ''));
$inviteUrl = trim((string)($_SESSION['guest_conversion_invite_url'] ?? ''));
unset($_SESSION['guest_conversion_notice'], $_SESSION['guest_conversion_invite_url']);
$guestRef = trim((string)($_GET['guest'] ?? $_POST['guest_ref'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if ((string)($_POST['action'] ?? '') !== 'approve_stay_invite') {
            throw new InvalidArgumentException('Unsupported guest conversion action.');
        }
        $guestRef = trim((string)($_POST['guest_ref'] ?? ''));
        $groupRef = trim((string)($_POST['group_ref'] ?? ''));
        $result = coveted_guest_conversion_approve_invite($admin, $guestRef, $groupRef, $pdo);
        $candidate = (array)$result['candidate'];
        $invitation = (array)$result['invitation'];
        $_SESSION['guest_conversion_notice'] = 'Invite to Stay approved for '
            . (string)$candidate['display_name']
            . '. They remain a Guest until they personally accept the invitation.';
        $_SESSION['guest_conversion_invite_url'] = (string)$invitation['url'];
        coveted_redirect('/admin/guest-conversions.php?guest=' . rawurlencode((string)$candidate['guest_ref']));
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Guest conversion approval failed: ' . $e->getMessage());
        $error = 'Unable to approve that Invite to Stay. Review the current guest evidence and try again.';
    }
}

$candidates = [];
$selected = null;
$counts = ['first_time' => 0, 'returning' => 0, 'conversion_ready' => 0, 'hold' => 0];
try {
    $candidates = coveted_guest_conversion_candidates($admin, 240, $pdo);
    foreach ($candidates as $candidate) {
        $state = (string)$candidate['state'];
        if (isset($counts[$state])) $counts[$state]++;
        if ($guestRef !== '' && ((string)$candidate['guest_ref'] === $guestRef || (string)$candidate['guest_user_id'] === $guestRef)) {
            $selected = $candidate;
        }
    }
    if (!$selected && $candidates) {
        $selected = $candidates[0];
        $guestRef = (string)$selected['guest_ref'];
    }
} catch (Throwable $e) {
    error_log('Guest Conversion workspace unavailable: ' . $e->getMessage());
    if ($error === '') $error = 'Guest Conversion Intelligence is temporarily unavailable.';
}

$stateLabel = static fn(string $state): string => match ($state) {
    'first_time' => 'First-time',
    'returning' => 'Returning',
    'conversion_ready' => 'Conversion-ready',
    'hold' => 'Hold / no pressure',
    default => ucwords(str_replace('_', ' ', $state)),
};
$fmt = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return 'Not recorded';
    try {
        return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y g:i A');
    } catch (Throwable) {
        return $value;
    }
};

coveted_page_start('Guest → Member Conversion', '', true);
coveted_admin_ui_start($admin, 'guest-conversions', 'Guest → Member Conversion');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PEOPLE · GUEST CONVERSION</span>
        <h1>Turn verified guest participation into an invitation—not an automatic enrollment.</h1>
        <p>Review first-time, returning, conversion-ready and no-pressure guests using completed Event attendance, group fit and known referral history. No personality inference or guest-value score is created.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/member-journeys.php">Member Journeys</a>
        <a class="cv-button cv-button-soft" href="/admin/membership-lifecycle.php">Membership Lifecycle</a>
        <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><strong><?= coveted_e($notice) ?></strong></div><?php endif; ?>
<?php if ($inviteUrl !== ''): ?>
<div class="cv-alert">
    <strong>Canonical acceptance link</strong><br>
    <span>This private link is for the invited guest. Acceptance is the only action that changes their group role from Guest to Member.</span>
    <div class="cv-action-row cv-admin-section-gap"><a class="cv-button cv-button-primary" href="<?= coveted_e($inviteUrl) ?>">Open invitation</a></div>
</div>
<?php endif; ?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Conversion-ready</span><strong><?= (int)$counts['conversion_ready'] ?></strong><small>repeat + recent best-fit evidence</small></div>
    <div><span>Returning</span><strong><?= (int)$counts['returning'] ?></strong><small>repeat verified guests</small></div>
    <div><span>First-time</span><strong><?= (int)$counts['first_time'] ?></strong><small>one verified completed Event</small></div>
    <div><span>No-pressure hold</span><strong><?= (int)$counts['hold'] ?></strong><small>pending or recently declined stay invite</small></div>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">CONVERSION QUEUE</span><h2>Qualified guests</h2></div><span class="cv-status">System Admin only</span></div>
    <div class="cv-admin-list">
        <?php if (!$candidates): ?>
            <div class="cv-admin-empty"><strong>No verified guest candidates yet.</strong><span>Active Guests appear after verified attendance at a completed Event.</span></div>
        <?php endif; ?>
        <?php foreach ($candidates as $candidate): $best = (array)$candidate['best_fit_group']; ?>
            <a class="cv-admin-list-row" href="/admin/guest-conversions.php?guest=<?= coveted_e(rawurlencode((string)$candidate['guest_ref'])) ?>">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$candidate['display_name']) ?></strong>
                    <small><?= coveted_e((string)$best['group_name']) ?> · <?= (int)$candidate['total_verified_events'] ?> verified completed Event<?= (int)$candidate['total_verified_events'] === 1 ? '' : 's' ?></small>
                    <small><?= coveted_e((string)$candidate['title']) ?></small>
                </span>
                <span class="cv-status"><?= coveted_e($stateLabel((string)$candidate['state'])) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($selected): $best = (array)$selected['best_fit_group']; $referrer = is_array($best['referrer'] ?? null) ? (array)$best['referrer'] : null; ?>
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">GUEST REVIEW</span><h2><?= coveted_e((string)$selected['display_name']) ?></h2></div>
        <span class="cv-status"><?= coveted_e($stateLabel((string)$selected['state'])) ?></span>
    </div>
    <p><?= coveted_e((string)$selected['detail']) ?></p>
    <div class="cv-alert"><strong>Evidence:</strong> <?= coveted_e((string)$selected['evidence']) ?></div>

    <dl class="cv-admin-event-definition-list">
        <div><dt>Account</dt><dd><?= coveted_e((string)$selected['email']) ?></dd></div>
        <div><dt>Best-fit group</dt><dd><?= coveted_e((string)$best['group_name']) ?></dd></div>
        <div><dt>Verified in best fit</dt><dd><?= (int)$best['verified_events'] ?> total · <?= (int)$best['verified_events_180d'] ?> in 180 days</dd></div>
        <div><dt>Last verified attendance</dt><dd><?= coveted_e($fmt((string)$best['last_verified_at'])) ?></dd></div>
        <div><dt>Known referrer</dt><dd><?= $referrer ? coveted_e((string)$referrer['display_name']) : 'Not established from canonical Guest Pass / membership history' ?></dd></div>
        <div><dt>Latest Invite to Stay</dt><dd><?= coveted_e((string)($best['latest_stay_status'] ?: 'None')) ?></dd></div>
    </dl>

    <?php if ((string)$selected['state'] === 'conversion_ready'): ?>
        <form method="post" class="cv-stack" onsubmit="return confirm('Approve an Invite to Stay for this guest? This sends no automatic enrollment: the guest remains a Guest until they accept the canonical invitation.');">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="approve_stay_invite">
            <input type="hidden" name="guest_ref" value="<?= coveted_e((string)$selected['guest_ref']) ?>">
            <input type="hidden" name="group_ref" value="<?= coveted_e((string)$best['group_ref']) ?>">
            <button class="cv-button cv-button-primary" type="submit">Approve Invite to Stay</button>
        </form>
    <?php elseif ((string)$selected['state'] === 'hold'): ?>
        <div class="cv-alert"><strong>No-pressure hold.</strong> Coveted will not recommend another membership invitation while a Stay invitation is active or during the 60-day pacing window after a decline.</div>
    <?php else: ?>
        <div class="cv-alert"><strong>No conversion action yet.</strong> Keep building verified participation. The Admin Agent may surface this guest only after the transparent conversion-ready rule is met.</div>
    <?php endif; ?>
</section>
<?php endif; ?>
</div>

<?php if ($selected): ?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">GROUP FIT</span><h2>Verified participation by active Guest group</h2></div></div>
    <div class="cv-admin-list">
        <?php foreach ((array)$selected['groups'] as $group): $groupReferrer = is_array($group['referrer'] ?? null) ? (array)$group['referrer'] : null; ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$group['group_name']) ?></strong>
                    <small><?= (int)$group['verified_events'] ?> verified completed Event<?= (int)$group['verified_events'] === 1 ? '' : 's' ?> · <?= (int)$group['verified_events_180d'] ?> in 180d · last <?= coveted_e($fmt((string)$group['last_verified_at'])) ?></small>
                    <?php if ($groupReferrer): ?><small>Relationship path: <?= coveted_e((string)$groupReferrer['display_name']) ?> · <?= coveted_e(str_replace('_', ' ', (string)$groupReferrer['source'])) ?></small><?php endif; ?>
                    <?php if (!empty($group['pending_stay_invite'])): ?><small>Active Invite to Stay already pending.</small><?php endif; ?>
                    <?php if (!empty($group['recent_stay_decline'])): ?><small>Stay invitation declined <?= coveted_e($fmt((string)$group['last_stay_declined_at'])) ?> · 60-day no-pressure window.</small><?php endif; ?>
                </span>
                <?php if ((string)$group['group_ref'] === (string)$selected['best_fit_group']['group_ref']): ?><span class="cv-status">Best fit</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY MODEL</span><h2>Recommendation → Admin approval → guest acceptance</h2></div></div>
    <p>The Agent receives aggregate conversion counts only. Exact guest identity, email, referrer and Event evidence stay inside this authorized System Admin workspace. Approving an Invite to Stay creates the existing canonical invitation; it does not update group membership. The Guest becomes a Member only after personally accepting that invitation.</p>
</section>
<?php endif; ?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>