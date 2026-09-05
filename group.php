<?php
declare(strict_types=1);

require_once __DIR__ . '/app/groups.php';
require_once __DIR__ . '/app/events.php';

$user = coveted_require_user();
$userId = (int)$user['id'];
$ref = trim((string)($_GET['id'] ?? ''));
$group = $ref !== '' ? coveted_group_by_ref($ref) : null;

if (!$group) {
    http_response_code(404);
    exit('Group not found.');
}

if (!coveted_group_can_view($group, $user)) {
    http_response_code(403);
    exit('This group is private.');
}

$error = '';
$notice = '';
$inviteLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'invite':
                $invitation = coveted_group_create_invitation(
                    $group,
                    $user,
                    (string)($_POST['email'] ?? '')
                );
                $inviteLink = $invitation['url'];
                $notice = 'Invitation created. Copy the private link below.';
                break;

            case 'update_role':
                coveted_group_update_member_role(
                    $group,
                    $user,
                    (int)($_POST['user_id'] ?? 0),
                    (string)($_POST['group_role'] ?? 'member')
                );
                $notice = 'Member role updated.';
                break;

            case 'remove_member':
                coveted_group_remove_member(
                    $group,
                    $user,
                    (int)($_POST['user_id'] ?? 0)
                );
                $notice = 'Member removed from the group.';
                break;

            case 'guest_pass':
                coveted_group_issue_guest_pass(
                    $group,
                    $user,
                    (int)($_POST['user_id'] ?? 0)
                );
                $notice = 'Guest pass issued.';
                break;

            case 'invite_guest':
                $invitation = coveted_group_create_invitation(
                    $group,
                    $user,
                    (string)($_POST['email'] ?? ''),
                    true
                );
                $inviteLink = $invitation['url'];
                $notice = 'Guest invitation created. Copy the private link below.';
                break;

            default:
                throw new InvalidArgumentException('Unsupported group action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted group error: ' . $e->getMessage());
        $error = 'Unable to complete that request.';
    }
}

if (!coveted_group_can_view($group, $user)) {
    coveted_redirect('/groups.php');
}

$canAdmin = coveted_group_can_admin($group, $user);
$canHost = coveted_group_can_host($group, $user);
$membership = coveted_group_membership((int)$group['id'], $userId);
$isActiveMember = $membership !== null
    && $membership['membership_status'] === 'active'
    && $membership['group_role'] !== 'guest';
$canSeeRoster = $canHost || $isActiveMember;

$members = [];
if ($canSeeRoster) {
    $membersStmt = coveted_db()->prepare(
        "SELECT gm.*, u.display_name, p.avatar_url,
                EXISTS (
                    SELECT 1 FROM user_roles ur
                    WHERE ur.user_id = u.id AND ur.role_key IN ('attendee_host','system_admin')
                ) AS host_approved
         FROM group_memberships gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN profiles p ON p.user_id = u.id
         WHERE gm.group_id = ? AND gm.membership_status = 'active'
         ORDER BY FIELD(gm.group_role, 'group_admin', 'host', 'member', 'guest'), u.display_name"
    );
    $membersStmt->execute([(int)$group['id']]);
    $members = $membersStmt->fetchAll();
}
$guestPassRecipients = array_values(array_filter(
    $members,
    static fn(array $member): bool => (string)$member['group_role'] !== 'guest'
));

$events = coveted_events_for_user($user, 20, (int)$group['id']);
$now = time();
$upcomingEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() >= $now
        && !in_array((string)$event['status'], ['completed', 'cancelled'], true)
));
usort(
    $upcomingEvents,
    static fn(array $a, array $b): int => strcmp((string)$a['starts_at'], (string)$b['starts_at'])
);
$historyEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() < $now
        || in_array((string)$event['status'], ['completed', 'cancelled'], true)
));
usort(
    $historyEvents,
    static fn(array $a, array $b): int => strcmp((string)$b['starts_at'], (string)$a['starts_at'])
);

$passesStmt = coveted_db()->prepare(
    "SELECT COUNT(*)
     FROM group_guest_passes
     WHERE group_id = ?
       AND issued_to_user_id = ?
       AND status = 'available'
       AND (expires_at IS NULL OR expires_at > NOW())"
);
$passesStmt->execute([(int)$group['id'], $userId]);
$myPasses = (int)$passesStmt->fetchColumn();

$memberCount = $canSeeRoster
    ? count(array_filter($members, static fn(array $member): bool => (string)$member['group_role'] !== 'guest'))
    : (int)$group['member_count'];
$myRole = coveted_is_system_admin($user)
    ? 'System Admin'
    : ($membership && $membership['membership_status'] === 'active'
        ? ucwords(str_replace('_', ' ', (string)$membership['group_role']))
        : (($membership['membership_status'] ?? '') === 'invited' ? 'Invited' : 'Visitor'));
$nextEvent = $upcomingEvents[0] ?? null;

$view = strtolower(trim((string)($_GET['view'] ?? 'overview')));
$allowedViews = ['overview', 'members', 'gatherings'];
if ($canHost || $canAdmin) {
    $allowedViews[] = 'manage';
}
if (!in_array($view, $allowedViews, true)) {
    $view = 'overview';
}

coveted_page_start((string)$group['name'], 'Groups');
?>
<section class="cv-page-heading">
    <a class="cv-text-link" href="/groups.php">← Back to Groups</a>
    <span class="cv-eyebrow"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$group['visibility']))) ?></span>
    <h1><?= coveted_e($group['name']) ?></h1>
    <p><?= coveted_e($group['description'] ?: 'A private Coveted community built around meeting in person.') ?></p>
</section>

<?php if ($error): ?>
    <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
<?php endif; ?>

<?php if ($notice): ?>
    <div class="cv-alert"><?= coveted_e($notice) ?></div>
<?php endif; ?>

<?php if ($inviteLink): ?>
    <div class="cv-card cv-copy-card">
        <span class="cv-eyebrow">PRIVATE INVITATION</span>
        <strong>Share this link only with the person you invited.</strong>
        <p class="cv-code-link"><?= coveted_e($inviteLink) ?></p>
        <p>It expires in 14 days and is tied to the intended invitation.</p>
    </div>
<?php endif; ?>

<section class="cv-stat-grid cv-home-stats" aria-label="Group summary">
    <a class="cv-card cv-stat" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=members">
        <strong><?= $memberCount ?></strong>
        <span>Members</span>
    </a>
    <a class="cv-card cv-stat" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=gatherings">
        <strong><?= count($upcomingEvents) ?></strong>
        <span>Upcoming</span>
    </a>
    <div class="cv-card cv-stat">
        <strong><?= $myPasses ?></strong>
        <span>Your guest passes</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= coveted_e($myRole) ?></strong>
        <span>Your role</span>
    </div>
</section>

<nav class="cv-tab-row" aria-label="Group workspace views">
    <a class="cv-tab <?= $view === 'overview' ? 'is-active' : '' ?>" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=overview">Overview</a>
    <a class="cv-tab <?= $view === 'members' ? 'is-active' : '' ?>" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=members">Members</a>
    <a class="cv-tab <?= $view === 'gatherings' ? 'is-active' : '' ?>" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=gatherings">Gatherings</a>
    <?php if ($canHost || $canAdmin): ?>
        <a class="cv-tab <?= $view === 'manage' ? 'is-active' : '' ?>" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=manage">Manage</a>
    <?php endif; ?>
</nav>

<?php if ($view === 'overview'): ?>
    <div class="cv-two-column">
        <section class="cv-stack">
            <?php if ($nextEvent): ?>
                <?php
                $showLocation = !empty($nextEvent['can_manage'])
                    || $nextEvent['location_visibility'] === 'immediate'
                    || (
                        $nextEvent['location_visibility'] === 'scheduled_reveal'
                        && !empty($nextEvent['location_revealed'])
                    );
                ?>
                <article class="cv-card cv-feature-card cv-copy-card">
                    <span class="cv-kicker">NEXT GATHERING</span>
                    <h2><?= coveted_e($nextEvent['title']) ?></h2>
                    <p>
                        <?= coveted_e(coveted_event_format($nextEvent, 'l, F j · g:i A')) ?>
                        <?php if ($showLocation && $nextEvent['location_name']): ?>
                            · <?= coveted_e($nextEvent['location_name']) ?>
                        <?php elseif (!$showLocation): ?>
                            · Location revealed later
                        <?php endif; ?>
                    </p>
                    <div class="cv-tag-row">
                        <?php if ($nextEvent['response'] === 'attending'): ?><span class="cv-pill">Attending</span><?php endif; ?>
                        <?php if ($nextEvent['response'] === 'waitlist'): ?><span class="cv-pill">Waitlist</span><?php endif; ?>
                        <?php if ($nextEvent['event_type'] === 'mystery'): ?><span class="cv-pill">Mystery</span><?php endif; ?>
                        <?php if (!empty($nextEvent['can_manage'])): ?><span class="cv-pill">Host</span><?php endif; ?>
                    </div>
                    <?php if (!empty($nextEvent['can_manage'])): ?>
                        <a class="cv-text-link" href="/host.php?event=<?= coveted_e($nextEvent['public_id']) ?>">Manage event →</a>
                    <?php elseif ($nextEvent['invitation_status'] === 'pending'): ?>
                        <a class="cv-text-link" href="/invitations.php?view=pending">Respond to invitation →</a>
                    <?php else: ?>
                        <a class="cv-text-link" href="/events.php?view=upcoming">Open Events →</a>
                    <?php endif; ?>
                </article>
            <?php else: ?>
                <article class="cv-card cv-empty">
                    <span class="cv-eyebrow">NEXT GATHERING</span>
                    <h2>Nothing scheduled yet.</h2>
                    <p>This group is between gatherings. The relationship is the product—not a feed to fill the space.</p>
                    <?php if ($canHost): ?><a class="cv-text-link" href="/host.php">Plan a gathering →</a><?php endif; ?>
                </article>
            <?php endif; ?>

            <article class="cv-card cv-copy-card">
                <span class="cv-eyebrow">ABOUT THIS GROUP</span>
                <h2><?= coveted_e($group['city'] ?: 'Location kept private') ?></h2>
                <p><?= coveted_e($group['description'] ?: 'A private Coveted community built around repeated in-person connection.') ?></p>
                <div class="cv-meta-row">
                    <span><?= $memberCount ?> member<?= $memberCount === 1 ? '' : 's' ?></span>
                    <span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$group['visibility']))) ?></span>
                    <?php if ($isActiveMember): ?><span><?= coveted_e($myRole) ?></span><?php endif; ?>
                </div>
            </article>
        </section>

        <aside class="cv-stack">
            <?php if ($isActiveMember && $myPasses > 0): ?>
                <form class="cv-card cv-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="invite_guest">
                    <span class="cv-eyebrow">YOUR INVITATION</span>
                    <h2><?= $myPasses ?> guest pass<?= $myPasses === 1 ? '' : 'es' ?></h2>
                    <p>Use one for someone you genuinely think belongs here.</p>
                    <label>
                        Email
                        <input type="email" name="email" maxlength="255" required>
                    </label>
                    <button class="cv-button cv-button-primary cv-button-block" type="submit">Invite Guest</button>
                </form>
            <?php endif; ?>

            <?php if ($canHost): ?>
                <article class="cv-card cv-copy-card">
                    <span class="cv-eyebrow">HOSTING</span>
                    <h2>Take the group offline.</h2>
                    <p>Use the Host Workspace to create the next gathering, assign cohosts, manage attendance and reveal mystery details.</p>
                    <a class="cv-text-link" href="/host.php">Open Host Workspace →</a>
                </article>
            <?php endif; ?>

            <article class="cv-card cv-copy-card">
                <span class="cv-eyebrow">GROUP PRINCIPLE</span>
                <h2>Meet again.</h2>
                <p>Coveted groups exist to make the second meeting more likely. Technology handles the coordination; people take it from there.</p>
            </article>
        </aside>
    </div>
<?php elseif ($view === 'members'): ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">MEMBERS</span>
            <h2><?= $canSeeRoster ? 'People in this group' : 'Private membership' ?></h2>
        </div>
        <?php if ($canAdmin): ?>
            <a class="cv-button cv-button-soft" href="/group.php?id=<?= coveted_e($group['public_id']) ?>&amp;view=manage">Manage Members</a>
        <?php endif; ?>
    </div>

    <section class="cv-stack">
        <?php if (!$canSeeRoster): ?>
            <div class="cv-card cv-empty">
                <h2>The member list stays inside the group.</h2>
                <p>Guest access and unlisted access do not expose the group's private social graph.</p>
            </div>
        <?php elseif (!$members): ?>
            <div class="cv-card cv-empty">
                <h2>No active members.</h2>
                <p>This group does not currently have an active roster.</p>
            </div>
        <?php else: ?>
            <?php foreach ($members as $member): ?>
                <div class="cv-card cv-member-row">
                    <div>
                        <strong><?= coveted_e($member['display_name']) ?></strong>
                        <span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$member['group_role']))) ?></span>
                    </div>
                    <div class="cv-tag-row">
                        <?php if (!empty($member['host_approved'])): ?><span class="cv-pill">Host approved</span><?php endif; ?>
                        <?php if ((int)$member['user_id'] === $userId): ?><span class="cv-pill">You</span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php elseif ($view === 'gatherings'): ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">GATHERINGS</span>
            <h2>Meetings around this group</h2>
        </div>
        <?php if ($canHost): ?><a class="cv-button cv-button-soft" href="/host.php">Host Workspace</a><?php endif; ?>
    </div>

    <section class="cv-stack">
        <?php if (!$upcomingEvents && !$historyEvents): ?>
            <div class="cv-card cv-empty">
                <h2>No gatherings yet.</h2>
                <p>When this group schedules an event, it will appear here.</p>
            </div>
        <?php endif; ?>

        <?php if ($upcomingEvents): ?>
            <span class="cv-eyebrow">UPCOMING</span>
            <?php foreach ($upcomingEvents as $event): ?>
                <article class="cv-card cv-event-row">
                    <div class="cv-event-date">
                        <strong><?= coveted_e(coveted_event_format($event, 'M')) ?></strong>
                        <span><?= coveted_e(coveted_event_format($event, 'j')) ?></span>
                    </div>
                    <div class="cv-event-copy">
                        <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$event['event_type']))) ?></span>
                        <h2><?= coveted_e($event['title']) ?></h2>
                        <p><?= coveted_e(coveted_event_format($event, 'l, F j · g:i A')) ?></p>
                        <div class="cv-tag-row">
                            <?php if ($event['response'] === 'attending'): ?><span class="cv-pill">Attending</span><?php endif; ?>
                            <?php if ($event['response'] === 'waitlist'): ?><span class="cv-pill">Waitlist</span><?php endif; ?>
                            <?php if ($event['event_type'] === 'mystery'): ?><span class="cv-pill">Mystery</span><?php endif; ?>
                            <?php if (!empty($event['can_manage'])): ?><span class="cv-pill">Host</span><?php endif; ?>
                        </div>
                        <?php if (!empty($event['can_manage'])): ?>
                            <a class="cv-text-link" href="/host.php?event=<?= coveted_e($event['public_id']) ?>">Manage event →</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($historyEvents): ?>
            <span class="cv-eyebrow">HISTORY</span>
            <?php foreach ($historyEvents as $event): ?>
                <article class="cv-card cv-event-row">
                    <div class="cv-event-date">
                        <strong><?= coveted_e(coveted_event_format($event, 'M')) ?></strong>
                        <span><?= coveted_e(coveted_event_format($event, 'j')) ?></span>
                    </div>
                    <div class="cv-event-copy">
                        <span class="cv-kicker"><?= coveted_e(strtoupper((string)$event['status'])) ?></span>
                        <h2><?= coveted_e($event['title']) ?></h2>
                        <p><?= coveted_e(coveted_event_format($event, 'l, F j · g:i A')) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php elseif ($view === 'manage'): ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">MANAGE</span>
            <h2>Group tools</h2>
        </div>
        <div class="cv-action-row">
            <?php if ($canAdmin): ?>
                <a class="cv-button cv-button-soft" href="/group-guests.php?group=<?= coveted_e($group['public_id']) ?>">Guest Continuity</a>
            <?php endif; ?>
            <?php if ($canHost): ?><a class="cv-button cv-button-primary" href="/host.php">Host Workspace</a><?php endif; ?>
        </div>
    </div>

    <div class="cv-two-column">
        <section class="cv-stack">
            <?php if ($canAdmin): ?>
                <article class="cv-card cv-copy-card">
                    <span class="cv-eyebrow">MEMBER ADMINISTRATION</span>
                    <h2>Roles and membership</h2>
                    <p>Only platform-approved Attendee Hosts can be promoted to Host or Group Admin. Guests become Members only by accepting an Invite to Stay after verified attendance.</p>
                </article>

                <?php foreach ($members as $member): ?>
                    <div class="cv-card cv-member-row">
                        <div>
                            <strong><?= coveted_e($member['display_name']) ?></strong>
                            <span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$member['group_role']))) ?></span>
                        </div>
                        <div class="cv-member-actions">
                            <?php if ($member['group_role'] === 'guest'): ?>
                                <span class="cv-pill">Guest · consent required</span>
                                <a class="cv-button cv-button-soft" href="/group-guests.php?group=<?= coveted_e($group['public_id']) ?>">Guest Continuity</a>
                            <?php else: ?>
                                <form method="post" class="cv-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                                    <select name="group_role" aria-label="Group role for <?= coveted_e($member['display_name']) ?>">
                                        <option value="member" <?= $member['group_role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                        <?php if (!empty($member['host_approved'])): ?>
                                            <option value="host" <?= $member['group_role'] === 'host' ? 'selected' : '' ?>>Host</option>
                                            <option value="group_admin" <?= $member['group_role'] === 'group_admin' ? 'selected' : '' ?>>Group Admin</option>
                                        <?php endif; ?>
                                    </select>
                                    <button class="cv-button cv-button-soft" type="submit">Save</button>
                                </form>
                            <?php endif; ?>

                            <?php if ((int)$member['user_id'] !== $userId || count($members) > 1): ?>
                                <form method="post" class="cv-inline-form" data-confirm="Remove this member from the group?">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="remove_member">
                                    <input type="hidden" name="user_id" value="<?= (int)$member['user_id'] ?>">
                                    <button class="cv-button cv-button-soft" type="submit">Remove</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <article class="cv-card cv-copy-card">
                    <span class="cv-eyebrow">HOST ROLE</span>
                    <h2>You manage gatherings, not membership.</h2>
                    <p>Group Admins control membership and role assignments. Hosts focus on the event experience.</p>
                    <a class="cv-text-link" href="/host.php">Open Host Workspace →</a>
                </article>
            <?php endif; ?>
        </section>

        <aside class="cv-stack">
            <?php if ($canAdmin): ?>
                <article class="cv-card cv-copy-card">
                    <span class="cv-eyebrow">GUEST CONTINUITY</span>
                    <h2>Turn a good gathering into a second one.</h2>
                    <p>See which Guests actually attended, then invite the right people to stay. No one becomes a Member automatically.</p>
                    <a class="cv-text-link" href="/group-guests.php?group=<?= coveted_e($group['public_id']) ?>">Open Guest Continuity →</a>
                </article>

                <form class="cv-card cv-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="invite">
                    <span class="cv-eyebrow">INVITE</span>
                    <h2>Add someone thoughtfully.</h2>
                    <label>
                        Email
                        <input type="email" name="email" maxlength="255" required>
                    </label>
                    <button class="cv-button cv-button-primary cv-button-block" type="submit">Create Invitation</button>
                </form>

                <?php if ($guestPassRecipients): ?>
                    <form class="cv-card cv-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="guest_pass">
                        <span class="cv-eyebrow">GUEST PASSES</span>
                        <h2>Give a member one invitation.</h2>
                        <label>
                            Member
                            <select name="user_id" required>
                                <?php foreach ($guestPassRecipients as $member): ?>
                                    <option value="<?= (int)$member['user_id'] ?>"><?= coveted_e($member['display_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button class="cv-button cv-button-soft cv-button-block" type="submit">Issue Guest Pass</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <article class="cv-card cv-copy-card">
                <span class="cv-eyebrow">GATHERINGS</span>
                <h2><?= count($upcomingEvents) ?> upcoming</h2>
                <p>Event operations live in the Host Workspace so group membership and event management remain separate responsibilities.</p>
                <?php if ($canHost): ?><a class="cv-text-link" href="/host.php">Open Host Workspace →</a><?php endif; ?>
            </article>
        </aside>
    </div>
<?php endif; ?>

<?php coveted_page_end(); ?>