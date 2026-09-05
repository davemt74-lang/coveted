<?php
declare(strict_types=1);

require_once __DIR__ . '/app/groups.php';
require_once __DIR__ . '/app/member_sample_data.php';

$user = coveted_require_user();
$pdo = coveted_db();
$userId = (int)$user['id'];
$sampleMode = coveted_member_sample_mode($user, $pdo);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($sampleMode) {
            throw new InvalidArgumentException('Sample groups are preview-only. Turn Sample Data off to manage live groups.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'request_host') {
            coveted_request_role($user, 'attendee_host', (string)($_POST['note'] ?? ''));
            $notice = 'Host request submitted for review.';
        } elseif ($action === 'create_group') {
            $created = coveted_create_group(
                $user,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['description'] ?? ''),
                (string)($_POST['city'] ?? ''),
                (string)($_POST['visibility'] ?? 'invite_only')
            );
            coveted_redirect('/group.php?id=' . rawurlencode((string)$created['public_id']));
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

if ($sampleMode) {
    $sample = coveted_member_sample_data();
    $groups = [];
    foreach ((array)($sample['groups'] ?? []) as $index => $group) {
        $groups[] = [
            'id' => 0,
            'public_id' => 'sample-' . (string)$group['id'],
            'name' => (string)$group['name'],
            'description' => (string)($group['description'] ?? ''),
            'city' => (string)($group['city'] ?? 'Phoenix, Arizona'),
            'visibility' => 'invite_only',
            'status' => 'active',
            'group_role' => $index === 0 ? 'member' : 'member',
            'membership_status' => 'active',
            'member_count' => (int)($group['members'] ?? 0),
            'upcoming_count' => 1,
            'next_event_title' => (string)($group['next'] ?? ''),
            'image' => (string)($group['image'] ?? ''),
            'is_sample' => true,
        ];
    }
} else {
    $stmt = $pdo->prepare(
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
                   (gm.membership_status = 'active' AND gm.group_role IN ('host','group_admin') AND e.status IN ('published','closed','draft'))
                   OR (
                       e.status IN ('published','closed')
                       AND (
                           (gm.membership_status = 'active' AND e.audience = 'group')
                           OR EXISTS (
                               SELECT 1 FROM event_invitations ei
                               WHERE ei.event_id = e.id AND ei.user_id = gm.user_id
                                 AND ei.status NOT IN ('expired','revoked')
                           )
                           OR EXISTS (
                               SELECT 1 FROM event_rsvps er
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
    foreach ($groups as &$group) {
        $group['next_event_title'] = '';
        $group['image'] = '';
        $group['is_sample'] = false;
    }
    unset($group);
}

$activeGroups = array_values(array_filter($groups, static fn(array $group): bool => $group['membership_status'] === 'active'));
$pendingGroups = array_values(array_filter($groups, static fn(array $group): bool => $group['membership_status'] === 'invited'));
$hostGroups = array_values(array_filter(
    $activeGroups,
    static fn(array $group): bool => in_array((string)$group['group_role'], ['host', 'group_admin'], true)
));

$isHost = !$sampleMode && (in_array('attendee_host', (array)$user['roles'], true) || coveted_is_system_admin($user));
$pendingHost = false;
if (!$sampleMode && !$isHost) {
    $pending = $pdo->prepare(
        "SELECT 1 FROM role_requests
         WHERE user_id = ? AND role_key = 'attendee_host' AND status = 'pending'
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
$featuredGroup = $view === 'active' ? ($activeGroups[0] ?? null) : null;

coveted_page_start('Groups', 'Groups');
?>
<div class="cv-member-page-v2 cv-groups-v2">
    <section class="cv-member-page-intro">
        <div>
            <span class="cv-eyebrow">GROUPS</span>
            <h1>Belong to what moves you.</h1>
            <p>Small communities built for repeated in-person connection. No follower counts, no public popularity contest, no feed to keep up with.</p>
        </div>
        <?php if ($sampleMode): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

    <nav class="cv-member-segmented-tabs" aria-label="Group views">
        <a class="<?= $view === 'active' ? 'is-active' : '' ?>" href="/groups.php?view=active"><span>Your groups</span><small><?= count($activeGroups) ?></small></a>
        <a class="<?= $view === 'pending' ? 'is-active' : '' ?>" href="/groups.php?view=pending"><span>Pending</span><?php if ($pendingGroups): ?><small><?= count($pendingGroups) ?></small><?php endif; ?></a>
        <?php if ($isHost): ?>
            <a class="<?= $view === 'hosting' ? 'is-active' : '' ?>" href="/groups.php?view=hosting"><span>Hosting</span><?php if ($hostGroups): ?><small><?= count($hostGroups) ?></small><?php endif; ?></a>
        <?php endif; ?>
    </nav>

    <?php if ($featuredGroup): ?>
        <?php $featuredImage = trim((string)($featuredGroup['image'] ?? '')); ?>
        <section class="cv-group-feature" aria-label="Featured group">
            <div class="cv-group-feature-media <?= $featuredImage === '' ? 'is-empty' : '' ?>">
                <?php if ($featuredImage !== ''): ?><img src="<?= coveted_e($featuredImage) ?>" alt="" loading="eager" decoding="async"><?php endif; ?>
            </div>
            <div class="cv-group-feature-copy">
                <span class="cv-member-overline">FEATURED GROUP</span>
                <h2><?= coveted_e((string)$featuredGroup['name']) ?></h2>
                <?php if (!empty($featuredGroup['description'])): ?><p><?= coveted_e((string)$featuredGroup['description']) ?></p><?php endif; ?>
                <dl class="cv-member-detail-list">
                    <div><dt>Members</dt><dd><?= (int)$featuredGroup['member_count'] ?> people</dd></div>
                    <div><dt>City</dt><dd><?= coveted_e((string)($featuredGroup['city'] ?: 'Location private')) ?></dd></div>
                    <div><dt>Next</dt><dd><?= !empty($featuredGroup['next_event_title']) ? coveted_e((string)$featuredGroup['next_event_title']) : ((int)$featuredGroup['upcoming_count'] > 0 ? (int)$featuredGroup['upcoming_count'] . ' upcoming gathering' . ((int)$featuredGroup['upcoming_count'] === 1 ? '' : 's') : 'Nothing scheduled yet') ?></dd></div>
                </dl>
                <div class="cv-event-feature-actions">
                    <?php if ($sampleMode): ?>
                        <span class="cv-member-preview-chip">Preview group</span>
                    <?php else: ?>
                        <a class="cv-button cv-button-primary" href="/group.php?id=<?= coveted_e(rawurlencode((string)$featuredGroup['public_id'])) ?>">Open group</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="cv-member-section-head">
        <div>
            <span class="cv-member-overline"><?= $view === 'pending' ? 'PRIVATE INVITATIONS' : ($view === 'hosting' ? 'HOSTING' : 'YOUR COMMUNITIES') ?></span>
            <h2><?= match ($view) {
                'pending' => 'Waiting for you to join.',
                'hosting' => 'Communities you help hold together.',
                default => 'The rooms you keep coming back to.',
            } ?></h2>
        </div>
    </section>

    <?php if (!$visibleGroups): ?>
        <div class="cv-member-empty-v2">
            <span>Nothing here yet</span>
            <h2><?= match ($view) {
                'pending' => 'No group invitations are waiting.',
                'hosting' => 'You are not hosting a group yet.',
                default => 'Your groups will live here.',
            } ?></h2>
            <p><?= match ($view) {
                'pending' => 'Private group invitations are completed from the secure invitation link you receive.',
                'hosting' => 'Hosting access appears here after approval and assignment.',
                default => 'Once you join a private community, its people and upcoming gatherings stay easy to find.',
            } ?></p>
        </div>
    <?php else: ?>
        <div class="cv-group-card-grid">
            <?php foreach ($visibleGroups as $group): ?>
                <?php
                $image = trim((string)($group['image'] ?? ''));
                $description = trim((string)($group['description'] ?? ''));
                ?>
                <article class="cv-group-card-v2" id="<?= coveted_e((string)$group['public_id']) ?>">
                    <div class="cv-group-card-media <?= $image === '' ? 'is-empty' : '' ?>">
                        <?php if ($image !== ''): ?><img src="<?= coveted_e($image) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    </div>
                    <div class="cv-group-card-copy">
                        <span class="cv-member-overline"><?= coveted_e((string)($group['city'] ?: 'PRIVATE GROUP')) ?></span>
                        <h3><?= coveted_e((string)$group['name']) ?></h3>
                        <?php if ($description !== ''): ?><p><?= coveted_e(mb_strimwidth($description, 0, 190, '…')) ?></p><?php endif; ?>
                        <div class="cv-member-card-meta">
                            <span><?= (int)$group['member_count'] ?> members</span>
                            <span><?= (int)$group['upcoming_count'] ?> upcoming</span>
                            <?php if ($group['membership_status'] === 'invited'): ?><span>Invitation waiting</span><?php endif; ?>
                        </div>
                        <?php if (!empty($group['next_event_title'])): ?><p class="cv-group-next">Next: <?= coveted_e((string)$group['next_event_title']) ?></p><?php endif; ?>
                        <div class="cv-event-card-actions">
                            <?php if ($sampleMode): ?>
                                <span class="cv-member-preview-chip">Preview</span>
                            <?php else: ?>
                                <a class="cv-member-text-link" href="/group.php?id=<?= coveted_e(rawurlencode((string)$group['public_id'])) ?>">Open group →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$sampleMode): ?>
        <section class="cv-group-tools-v2">
            <?php if ($isHost): ?>
                <form id="create-group" class="cv-group-tool-card cv-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_group">
                    <span class="cv-member-overline">HOST TOOLS</span>
                    <h2>Create a private group.</h2>
                    <p>Start with a focused community. Coveted Admin remains responsible for creating and configuring events.</p>
                    <label>Group name<input name="name" maxlength="180" required></label>
                    <label>City<input name="city" maxlength="160" placeholder="Phoenix"></label>
                    <label>Description<textarea name="description" rows="4" maxlength="2000" placeholder="What brings this group together?"></textarea></label>
                    <label>Visibility
                        <select name="visibility">
                            <option value="invite_only">Invite only</option>
                            <option value="private">Private</option>
                            <option value="unlisted">Unlisted link</option>
                        </select>
                    </label>
                    <button class="cv-button cv-button-primary" type="submit">Create group</button>
                </form>
                <article class="cv-group-tool-card">
                    <span class="cv-member-overline">HOST WORKSPACE</span>
                    <h2>Support the gathering.</h2>
                    <p>When Coveted Admin assigns you to an event, invitations, attendance and check-in tools appear in the Host Workspace.</p>
                    <a class="cv-member-text-link" href="/host.php">Open Host Workspace →</a>
                </article>
            <?php else: ?>
                <form class="cv-group-tool-card cv-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="request_host">
                    <span class="cv-member-overline">HOSTING</span>
                    <h2>Want to bring people together?</h2>
                    <p>Attendee Hosts can create private groups and support gatherings assigned by Coveted Admin.</p>
                    <?php if ($pendingHost): ?>
                        <span class="cv-member-status-chip">Host request pending</span>
                    <?php else: ?>
                        <label>Why do you want to host? <span>(optional)</span><textarea name="note" rows="4" maxlength="2000"></textarea></label>
                        <button class="cv-button cv-button-primary" type="submit">Request host access</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <article class="cv-group-tool-card">
                <span class="cv-member-overline">PRIVATE BY DESIGN</span>
                <h2>Groups exist for the second meeting.</h2>
                <p>Membership, invitations and gatherings remain contextual instead of turning into public social metrics.</p>
            </article>
        </section>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
