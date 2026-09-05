<?php
declare(strict_types=1);

require_once __DIR__ . '/app/groups.php';

$user = coveted_require_user();
$userId = (int)$user['id'];
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'request_host') {
            coveted_request_role(
                $user,
                'attendee_host',
                (string)($_POST['note'] ?? '')
            );
            $notice = 'Host request submitted for review.';
        } elseif ($action === 'create_group') {
            $created = coveted_create_group(
                $user,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                (string)($_POST['city'] ?? ''),
                (string)($_POST['visibility'] ?? 'invite_only')
            );

            coveted_redirect('/group.php?id=' . rawurlencode($created['public_id']));
        } else {
            throw new InvalidArgumentException('Unsupported group action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted groups error: ' . $e->getMessage());
        $error = 'Unable to complete that request.';
    }
}

$stmt = coveted_db()->prepare(
    "SELECT
        g.*,
        gm.group_role,
        gm.membership_status,
        (SELECT COUNT(*)
         FROM group_memberships x
         WHERE x.group_id = g.id AND x.membership_status = 'active') AS member_count,
        (SELECT COUNT(*)
         FROM events e
         WHERE e.group_id = g.id
           AND e.starts_at >= NOW()
           AND (
               (
                   gm.membership_status = 'active'
                   AND gm.group_role IN ('host','group_admin')
                   AND e.status IN ('published','closed','draft')
               )
               OR (
                   e.status IN ('published','closed')
                   AND (
                       (
                           gm.membership_status = 'active'
                           AND e.audience = 'group'
                       )
                       OR EXISTS (
                           SELECT 1
                           FROM event_invitations ei
                           WHERE ei.event_id = e.id
                             AND ei.user_id = gm.user_id
                             AND ei.status NOT IN ('expired','revoked')
                       )
                       OR EXISTS (
                           SELECT 1
                           FROM event_rsvps er
                           WHERE er.event_id = e.id AND er.user_id = gm.user_id
                       )
                   )
               )
           )) AS upcoming_count
     FROM group_memberships gm
     JOIN social_groups g ON g.id = gm.group_id
     WHERE gm.user_id = ?
       AND gm.membership_status IN ('active','invited')
       AND g.status <> 'archived'
     ORDER BY
        FIELD(gm.membership_status, 'active', 'invited'),
        FIELD(gm.group_role, 'group_admin', 'host', 'member', 'guest'),
        g.updated_at DESC"
);
$stmt->execute([$userId]);
$groups = $stmt->fetchAll();

$activeGroups = array_values(array_filter(
    $groups,
    static fn(array $group): bool => $group['membership_status'] === 'active'
));
$pendingGroups = array_values(array_filter(
    $groups,
    static fn(array $group): bool => $group['membership_status'] === 'invited'
));
$hostGroups = array_values(array_filter(
    $activeGroups,
    static fn(array $group): bool => in_array((string)$group['group_role'], ['host', 'group_admin'], true)
));

$upcomingTotal = array_sum(array_map(
    static fn(array $group): int => (int)$group['upcoming_count'],
    $activeGroups
));

$isHost = in_array('attendee_host', (array)$user['roles'], true) || coveted_is_system_admin($user);
$pendingHost = false;

if (!$isHost) {
    $pending = coveted_db()->prepare(
        "SELECT 1
         FROM role_requests
         WHERE user_id = ?
           AND role_key = 'attendee_host'
           AND status = 'pending'
         LIMIT 1"
    );
    $pending->execute([$userId]);
    $pendingHost = (bool)$pending->fetchColumn();
}

$view = strtolower(trim((string)($_GET['view'] ?? 'active')));
if (!in_array($view, ['active', 'pending', 'hosting'], true)) {
    $view = 'active';
}
if ($view === 'hosting' && !$isHost) {
    $view = 'active';
}

$visibleGroups = match ($view) {
    'pending' => $pendingGroups,
    'hosting' => $hostGroups,
    default => $activeGroups,
};

coveted_page_start('Groups', 'Groups');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">GROUPS</span>
    <h1>Your circles.</h1>
    <p>Private communities built around repeated in-person connection—not feeds, followers or public popularity.</p>
</section>

<?php if ($error): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>

<?php if ($notice): ?>
    <div class="cv-alert"><?= coveted_e($notice) ?></div>
<?php endif; ?>

<section class="cv-stat-grid cv-home-stats" aria-label="Group summary">
    <a class="cv-card cv-stat" href="/groups.php?view=active">
        <strong><?= count($activeGroups) ?></strong>
        <span>Active groups</span>
    </a>
    <a class="cv-card cv-stat" href="/groups.php?view=pending">
        <strong><?= count($pendingGroups) ?></strong>
        <span>Pending access</span>
    </a>
    <a class="cv-card cv-stat" href="/events.php?view=upcoming">
        <strong><?= $upcomingTotal ?></strong>
        <span>Upcoming gatherings</span>
    </a>
    <a class="cv-card cv-stat" href="<?= $isHost ? '/groups.php?view=hosting' : '/profile.php' ?>">
        <strong><?= count($hostGroups) ?></strong>
        <span><?= $isHost ? 'Groups you host' : 'Hosting access' ?></span>
    </a>
</section>

<div class="cv-two-column">
    <section>
        <div class="cv-section-head">
            <div>
                <span class="cv-eyebrow">YOUR COMMUNITIES</span>
                <h2><?= match ($view) {
                    'pending' => 'Pending group access',
                    'hosting' => 'Groups you help lead',
                    default => 'Active memberships',
                } ?></h2>
            </div>
            <span class="cv-status"><?= count($visibleGroups) ?> shown</span>
        </div>

        <nav class="cv-tab-row" aria-label="Group views">
            <a class="cv-tab <?= $view === 'active' ? 'is-active' : '' ?>" href="/groups.php?view=active">Active · <?= count($activeGroups) ?></a>
            <a class="cv-tab <?= $view === 'pending' ? 'is-active' : '' ?>" href="/groups.php?view=pending">Pending · <?= count($pendingGroups) ?></a>
            <?php if ($isHost): ?>
                <a class="cv-tab <?= $view === 'hosting' ? 'is-active' : '' ?>" href="/groups.php?view=hosting">Hosting · <?= count($hostGroups) ?></a>
            <?php endif; ?>
        </nav>

        <div class="cv-stack">
            <?php if (!$visibleGroups): ?>
                <div class="cv-card cv-empty">
                    <h3><?= match ($view) {
                        'pending' => 'No pending group access.',
                        'hosting' => 'You are not leading a group yet.',
                        default => 'No active groups yet.',
                    } ?></h3>
                    <p><?= match ($view) {
                        'pending' => 'Private group invitations are completed from the secure invitation link you receive.',
                        'hosting' => 'Create a focused group when you have a community ready to meet in person.',
                        default => 'Your private memberships will appear here after you join a group.',
                    } ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ($visibleGroups as $group): ?>
                <?php
                $roleLabel = $group['membership_status'] === 'invited'
                    ? 'Pending access'
                    : ucwords(str_replace('_', ' ', (string)$group['group_role']));
                $description = trim((string)($group['description'] ?? ''));
                ?>
                <a class="cv-card cv-group-row" href="/group.php?id=<?= coveted_e($group['public_id']) ?>">
                    <div>
                        <div class="cv-tag-row">
                            <span class="cv-kicker"><?= coveted_e(strtoupper($roleLabel)) ?></span>
                            <?php if ($group['visibility'] !== 'invite_only'): ?>
                                <span class="cv-pill"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$group['visibility']))) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3><?= coveted_e($group['name']) ?></h3>
                        <p><?= coveted_e($group['city'] ?: 'Location private') ?></p>
                        <?php if ($description !== ''): ?>
                            <p><?= coveted_e(mb_strimwidth($description, 0, 180, '…')) ?></p>
                        <?php endif; ?>
                        <?php if ($group['membership_status'] === 'invited'): ?>
                            <p class="cv-form-help">Use the private invitation link you received to accept or decline access.</p>
                        <?php endif; ?>
                    </div>
                    <div class="cv-group-stats">
                        <strong><?= (int)$group['member_count'] ?></strong>
                        <span>members</span>
                        <strong><?= (int)$group['upcoming_count'] ?></strong>
                        <span>upcoming</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="cv-stack">
        <?php if ($isHost): ?>
            <form id="create-group" class="cv-card cv-form cv-anchor-target" method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_group">
                <span class="cv-eyebrow">HOST TOOLS</span>
                <h2>Create a group</h2>
                <p>Start with a focused community. Membership stays intentional and invitation-led.</p>

                <label>
                    Group name
                    <input name="name" maxlength="180" required>
                </label>
                <label>
                    City
                    <input name="city" maxlength="160" placeholder="Phoenix">
                </label>
                <label>
                    Description
                    <textarea name="description" rows="4" maxlength="2000" placeholder="What brings this group together?"></textarea>
                </label>
                <label>
                    Visibility
                    <select name="visibility">
                        <option value="invite_only">Invite only</option>
                        <option value="private">Private</option>
                        <option value="unlisted">Unlisted link</option>
                    </select>
                </label>

                <button class="cv-button cv-button-primary cv-button-block" type="submit">Create Group</button>
            </form>

            <article class="cv-card cv-copy-card">
                <span class="cv-eyebrow">HOST WORKSPACE</span>
                <h2>Plan the gathering, not the feed.</h2>
                <p>Group Hosts can move directly into the Event Host Workspace when the community is ready to meet.</p>
                <a class="cv-text-link" href="/host.php">Open Host Workspace →</a>
            </article>
        <?php else: ?>
            <form class="cv-card cv-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="request_host">
                <span class="cv-eyebrow">HOSTING</span>
                <h2>Want to bring people together?</h2>
                <p>Attendee Hosts can create groups, curate membership and organize Coveted gatherings.</p>

                <?php if ($pendingHost): ?>
                    <div class="cv-status">Host request pending</div>
                <?php else: ?>
                    <label>
                        Why do you want to host? <span>(optional)</span>
                        <textarea name="note" rows="4" maxlength="2000"></textarea>
                    </label>
                    <button class="cv-button cv-button-primary cv-button-block" type="submit">Request Host Access</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <article class="cv-card cv-copy-card">
            <span class="cv-eyebrow">HOW GROUPS WORK</span>
            <h2>Private by design.</h2>
            <p>Groups exist to make second meetings more likely. Membership, invitations and gatherings stay contextual instead of becoming public social metrics.</p>
        </article>
    </aside>
</div>
<?php coveted_page_end(); ?>
