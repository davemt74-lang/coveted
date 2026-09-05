from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one match, found {count}")
    return text.replace(old, new, 1)


def sub_once(text: str, pattern: str, repl: str, label: str, flags: int = 0) -> str:
    out, count = re.subn(pattern, repl, text, count=1, flags=flags)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one regex match, found {count}")
    return out


# app/events.php
p = Path("app/events.php")
text = p.read_text()

old = """function coveted_event_actor_has_host_approval(array $user): bool
{
    return coveted_is_system_admin($user)
        || in_array('attendee_host', (array)($user['roles'] ?? []), true);
}
"""
new = old + """
function coveted_event_require_system_admin(array $actor): void
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('Coveted System Admin access is required for event configuration.');
    }
}
"""
text = replace_once(text, old, new, "insert admin authority helper")

old = """function coveted_event_can_manage(array $event, array $user): bool
{
    if (coveted_is_system_admin($user)) {
        return true;
    }
    if (!coveted_event_actor_has_host_approval($user)) {
        return false;
    }

    $groupRole = coveted_event_group_role((int)$event['group_id'], (int)$user['id']);
    if (in_array($groupRole, ['host', 'group_admin'], true)) {
        return true;
    }

    return in_array(
        coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']),
        ['lead', 'cohost'],
        true
    );
}
"""
new = """function coveted_event_can_manage(array $event, array $user): bool
{
    if (coveted_is_system_admin($user)) {
        return true;
    }
    if (!coveted_event_actor_has_host_approval($user)) {
        return false;
    }

    return in_array(
        coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']),
        ['lead', 'cohost'],
        true
    );
}
"""
text = replace_once(text, old, new, "scope event management to assignments")

old = """    $stmt = coveted_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
"""
new = """    $stmt = coveted_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($isSystemAdmin) {
        return $rows;
    }

    $visible = [];
    foreach ($rows as $row) {
        $assignedRole = coveted_event_assigned_host_role((int)$row['id'], $userId);
        if ((string)$row['status'] === 'draft' && $assignedRole === null) {
            continue;
        }
        $row['can_manage'] = $isHostApproved
            && in_array($assignedRole, ['lead', 'cohost'], true)
            ? 1
            : 0;
        $visible[] = $row;
    }

    return $visible;
}
"""
text = replace_once(text, old, new, "normalize event visibility and can_manage")

for signature, label in [
    ("function coveted_event_create(array $actor, int $groupId, array $data): array\n{", "create event"),
    ("function coveted_event_set_status(array $actor, string $eventRef, string $status): void\n{", "set event status"),
    ("function coveted_event_assign_host(array $actor, string $eventRef, int $userId, string $hostRole): void\n{", "assign event host"),
]:
    text = replace_once(
        text,
        signature,
        signature + "\n    coveted_event_require_system_admin($actor);",
        f"guard {label}",
    )

p.write_text(text)


# app/event_management.php
p = Path("app/event_management.php")
text = p.read_text()
patterns = [
    (r"(function coveted_event_update\(array \$actor, string \$eventRef, array \$data\): void\n\{)", "event update"),
    (r"(function coveted_event_set_location\(\n    array \$actor,\n    string \$eventRef,\n    \?int \$locationId,\n    string \$privateLabel = '',\n    string \$revealNotes = ''\n\): void \{)", "event location"),
    (r"(function coveted_event_set_artist\(\n    array \$actor,\n    string \$eventRef,\n    int \$artistId,\n    string \$appearanceType = 'featured'\n\): void \{)", "event artist"),
    (r"(function coveted_event_remove_artist\(array \$actor, string \$eventRef, int \$artistId\): void\n\{)", "remove event artist"),
    (r"(function coveted_event_add_mystery_reveal\(\n    array \$actor,\n    string \$eventRef,\n    string \$revealAt,\n    string \$revealType,\n    string \$title,\n    string \$content\n\): void \{)", "mystery reveal"),
]
for pattern, label in patterns:
    text = sub_once(text, pattern, r"\1\n    coveted_event_require_system_admin($actor);", f"guard {label}")
p.write_text(text)


# host.php
p = Path("host.php")
text = p.read_text()
text = replace_once(
    text,
    "$user = coveted_require_user();\n",
    "$user = coveted_require_user();\n$isSystemAdmin = coveted_is_system_admin($user);\n",
    "cache system admin role",
)
text = text.replace(
    "Coveted Attendee Host approval is required before you can create or manage gatherings.",
    "Coveted Attendee Host approval is required before you can manage assigned gatherings.",
)

create_action = """        if ($action === 'create_event') {
            $data = coveted_host_event_input($_POST);
            $data['status'] = (string)($_POST['status'] ?? 'draft');
            $created = coveted_event_create($user, (int)($_POST['group_id'] ?? 0), $data);
            coveted_redirect('/host.php?event=' . rawurlencode((string)$created['public_id']) . '&saved=created');
        }

"""
text = replace_once(text, create_action, "", "remove host create action")

old = """        $action = trim((string)($_POST['action'] ?? ''));

"""
new = """        $action = trim((string)($_POST['action'] ?? ''));
        $adminOnlyActions = [
            'update_event', 'set_status', 'set_location', 'set_artist',
            'remove_artist', 'assign_host', 'add_reveal',
        ];
        if (!$isSystemAdmin && in_array($action, $adminOnlyActions, true)) {
            throw new InvalidArgumentException('Event configuration is controlled by Coveted System Admin.');
        }

"""
text = replace_once(text, old, new, "host admin-only action guard")

text = sub_once(
    text,
    r"if \(coveted_is_system_admin\(\$user\)\) \{.*?\n\$hostGroups = \$groupStmt->fetchAll\(\);\n\n",
    "",
    "remove host event-creation group query",
    re.S,
)

old = """$manageableEvents = array_values(array_filter(
    coveted_events_for_user($user, 250),
    static fn(array $event): bool => !empty($event['can_manage'])
));
"""
new = """$hostWorkspaceEvents = coveted_events_for_user($user, 250);
$manageableEvents = array_values(array_filter(
    $hostWorkspaceEvents,
    static function (array $event) use ($user, $isSystemAdmin): bool {
        return $isSystemAdmin
            || coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) !== null;
    }
));
"""
text = replace_once(text, old, new, "scope host workspace event list")

old = """if ($eventRef !== '') {
    $selectedEvent = coveted_event_by_ref($eventRef);
    if (!$selectedEvent || !coveted_event_can_manage($selectedEvent, $user)) {
        http_response_code(404);
        $error = 'Event not found or you no longer have host access.';
        $selectedEvent = null;
        $eventRef = '';
    }
}
"""
new = """if ($eventRef !== '') {
    $selectedEvent = coveted_event_by_ref($eventRef);
    $assignedHostRole = $selectedEvent && !$isSystemAdmin
        ? coveted_event_assigned_host_role((int)$selectedEvent['id'], (int)$user['id'])
        : ($isSystemAdmin ? 'system_admin' : null);
    if (!$selectedEvent || (!$isSystemAdmin && $assignedHostRole === null)) {
        http_response_code(404);
        $error = 'Event not found or you are no longer assigned to this gathering.';
        $selectedEvent = null;
        $eventRef = '';
    }
}
"""
text = replace_once(text, old, new, "allow assigned check-in hosts into workspace")

old = """$canInvite = $selectedEvent
    && $selectedEvent['status'] === 'published'
    && coveted_event_is_future($selectedEvent);
$canRecordAttendance = $selectedEvent
    && !in_array((string)$selectedEvent['status'], ['draft', 'cancelled'], true);
$canConfigure = $selectedEvent && !$isFinalEvent;
"""
new = """$canInvite = $selectedEvent
    && coveted_event_can_manage($selectedEvent, $user)
    && $selectedEvent['status'] === 'published'
    && coveted_event_is_future($selectedEvent);
$canRecordAttendance = $selectedEvent
    && coveted_event_can_checkin($selectedEvent, $user)
    && !in_array((string)$selectedEvent['status'], ['draft', 'cancelled'], true);
$canConfigure = $selectedEvent && $isSystemAdmin && !$isFinalEvent;
"""
text = replace_once(text, old, new, "separate host operation permissions")

text = text.replace(
    "<h1><?= $selectedEvent ? coveted_e($selectedEvent['title']) : 'Plan the gathering.' ?></h1>",
    "<h1><?= $selectedEvent ? coveted_e($selectedEvent['title']) : 'Assigned gatherings.' ?></h1>",
)
text = text.replace(
    "? 'Manage the details before people arrive. During the gathering, Coveted gets out of the way.'\n        : 'Create a gathering from an approved group, then manage invitations, experience details and attendance here.' ?>",
    "? ($isSystemAdmin ? 'Configure the gathering and coordinate its assigned host team.' : 'Support the guest list and event-day experience. Coveted Admin controls the event setup.')\n        : ($isSystemAdmin ? 'Choose an event to configure, or create one from Admin Events.' : 'Your assigned gatherings appear here when Coveted Admin adds you to the host team.') ?>",
)
text = text.replace(
    "<h2>Events</h2>\n        <a class=\"<?= !$selectedEvent ? 'is-active' : '' ?>\" href=\"/host.php\">Create event</a>",
    "<h2>Assignments</h2>",
)

text = sub_once(
    text,
    r"<\?php if \(!\$selectedEvent\): \?>.*?<\?php else: \?>\s*<\?php\s*\$startLocal",
    """<?php if (!$selectedEvent): ?>
            <div class="cv-card cv-empty">
                <?php if ($isSystemAdmin): ?>
                    <h2>Choose an event to manage.</h2>
                    <p>System Admin creates events and assigns the host team from the Admin Events workflow.</p>
                    <a class="cv-button" href="/admin/?view=events">Open Admin Events</a>
                <?php else: ?>
                    <h2>No assigned events yet.</h2>
                    <p>Coveted Admin creates each gathering and assigns Attendee Hosts when event-day support is needed.</p>
                    <a class="cv-button" href="/events.php">Open My Events</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php
            $startLocal""",
    "replace host create form with assignment state",
    re.S,
)

text = text.replace("Completed and cancelled event setup is read-only.", "Event setup is controlled by Coveted System Admin.")
text = text.replace("Location record is read-only for this event.", "Location is controlled by Coveted System Admin.")
text = text.replace("Completed and cancelled events keep their artist history read-only.", "Artist lineup is managed by Coveted System Admin.")
text = text.replace(
    "<h2>Reveal timeline locked.</h2>\n                            <p>Completed and cancelled event reveal history remains available below.</p>",
    "<h2>Admin-managed reveal timeline.</h2>\n                            <p>Coveted System Admin controls mystery reveal timing and content.</p>",
)

old = """                            <div class="cv-action-row">
                                <?php foreach ($statusTransitions[(string)$selectedEvent['status']] ?? [] as $value => $label): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                        <input type="hidden" name="status" value="<?= coveted_e($value) ?>">
                                        <button class="cv-button <?= $value === 'cancelled' ? 'cv-button-soft' : '' ?>" type="submit"><?= coveted_e($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
"""
new = """                            <div class="cv-action-row">
                                <?php if ($canConfigure): ?>
                                    <?php foreach ($statusTransitions[(string)$selectedEvent['status']] ?? [] as $value => $label): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                            <input type="hidden" name="status" value="<?= coveted_e($value) ?>">
                                            <button class="cv-button <?= $value === 'cancelled' ? 'cv-button-soft' : '' ?>" type="submit"><?= coveted_e($label) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php elseif (!$isSystemAdmin): ?>
                                    <span class="cv-status">Admin controlled</span>
                                <?php endif; ?>
                            </div>
"""
text = replace_once(text, old, new, "hide event status controls from hosts")
p.write_text(text)


# events.php
p = Path("events.php")
text = p.read_text()
text = replace_once(
    text,
    "$isApprovedHost = coveted_event_actor_has_host_approval($user);\n",
    "$isApprovedHost = coveted_event_actor_has_host_approval($user);\n$isSystemAdmin = coveted_is_system_admin($user);\nforeach ($events as &$event) {\n    $event['assigned_host_role'] = $isSystemAdmin\n        ? 'system_admin'\n        : (coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) ?? '');\n}\nunset($event);\n",
    "annotate host assignments in events calendar",
)
text = text.replace(
    "static fn(array $event): bool => !empty($event['can_manage'])",
    "static fn(array $event): bool => !empty($event['assigned_host_role'])",
)
text = replace_once(
    text,
    "$featuredCanManage = (bool)$featuredEvent['can_manage'];\n    $featuredShowLocation = $featuredCanManage",
    "$featuredCanManage = (bool)$featuredEvent['can_manage'];\n    $featuredIsHost = $featuredCanManage || !empty($featuredEvent['assigned_host_role']);\n    $featuredShowLocation = $featuredIsHost",
    "featured host assignment state",
)
text = text.replace("($featuredCanManage\n            ? 'NEXT EVENT YOU HOST'", "($featuredIsHost\n            ? 'NEXT EVENT YOU HOST'")
text = text.replace("<?php if ($featuredCanManage): ?><span class=\"cv-pill\">Host</span><?php endif; ?>", "<?php if ($featuredIsHost): ?><span class=\"cv-pill\">Host</span><?php endif; ?>")
text = text.replace("<?php if ($featuredCanManage): ?>\n                <a class=\"cv-button cv-button-soft\" href=\"/host.php?event=<?= coveted_e($featuredEvent['public_id']) ?>\">Manage Event</a>", "<?php if ($featuredIsHost): ?>\n                <a class=\"cv-button cv-button-soft\" href=\"/host.php?event=<?= coveted_e($featuredEvent['public_id']) ?>\">Host Workspace</a>")
text = text.replace("'hosting' => 'Events you manage',", "'hosting' => 'Your host assignments',")
text = text.replace("'mystery' => 'Mystery events will appear here when your groups or hosts create them.',", "'mystery' => 'Mystery events will appear here when Coveted Admin schedules them for your groups.',")
text = text.replace("'hosting' => 'Create an event when one of your groups is ready to meet.',", "'hosting' => 'Coveted Admin will assign you when a gathering needs host support.',")
text = text.replace("            <?php if ($view === 'hosting' && $isApprovedHost): ?><a class=\"cv-button\" href=\"/host.php\">Create an Event</a><?php endif; ?>\n", "            <?php if ($view === 'hosting' && $isApprovedHost): ?><a class=\"cv-button\" href=\"/host.php\">Open Host Workspace</a><?php endif; ?>\n")
text = replace_once(
    text,
    "$canManage = (bool)$event['can_manage'];\n        $verifiedAttendance",
    "$canManage = (bool)$event['can_manage'];\n        $isHostAssignment = $canManage || !empty($event['assigned_host_role']);\n        $verifiedAttendance",
    "event row host assignment state",
)
text = text.replace("$showLocation = $canManage", "$showLocation = $isHostAssignment")
text = text.replace("<?php if ($canManage): ?><span class=\"cv-pill\">Host</span><?php endif; ?>", "<?php if ($isHostAssignment): ?><span class=\"cv-pill\">Host</span><?php endif; ?>")
text = text.replace("<?php if ($canManage): ?>\n                        <a class=\"cv-button cv-button-soft\" href=\"/host.php?event=<?= coveted_e($event['public_id']) ?>\">Manage Event</a>", "<?php if ($isHostAssignment): ?>\n                        <a class=\"cv-button cv-button-soft\" href=\"/host.php?event=<?= coveted_e($event['public_id']) ?>\">Host Workspace</a>")
p.write_text(text)


# group.php
p = Path("group.php")
text = p.read_text()
text = text.replace(
    "<?php if ($canHost): ?><a class=\"cv-text-link\" href=\"/host.php\">Plan a gathering →</a><?php endif; ?>",
    "<?php if ($canHost): ?><a class=\"cv-text-link\" href=\"/host.php\">View host assignments →</a><?php endif; ?>",
)
text = text.replace(
    "Use the Host Workspace to create the next gathering, assign cohosts, manage attendance and reveal mystery details.",
    "Coveted Admin creates and configures gatherings. Use the Host Workspace for assigned guest-list, check-in and event-day responsibilities.",
)
p.write_text(text)


# Permanent authority contract verifier
Path("scripts/verify-event-authority.php").write_text(r'''<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$events = $read('app/events.php');
$management = $read('app/event_management.php');
$host = $read('host.php');
$calendar = $read('events.php');
$group = $read('group.php');

$mustContain = [
    ['app/events.php', $events, 'function coveted_event_require_system_admin(array $actor): void'],
    ['app/events.php', $events, "function coveted_event_create(array $actor, int $groupId, array $data): array\n{\n    coveted_event_require_system_admin($actor);"],
    ['app/events.php', $events, "function coveted_event_set_status(array $actor, string $eventRef, string $status): void\n{\n    coveted_event_require_system_admin($actor);"],
    ['app/events.php', $events, "function coveted_event_assign_host(array $actor, string $eventRef, int $userId, string $hostRole): void\n{\n    coveted_event_require_system_admin($actor);"],
    ['host.php', $host, '$canConfigure = $selectedEvent && $isSystemAdmin && !$isFinalEvent;'],
    ['host.php', $host, 'No assigned events yet.'],
    ['events.php', $calendar, "'hosting' => 'Your host assignments'"],
    ['group.php', $group, 'Coveted Admin creates and configures gatherings.'],
];
foreach ($mustContain as [$path, $content, $needle]) {
    if (!str_contains($content, $needle)) {
        $failures[] = $path . ' missing authority contract: ' . $needle;
    }
}

foreach (['coveted_event_update', 'coveted_event_set_location', 'coveted_event_set_artist', 'coveted_event_remove_artist', 'coveted_event_add_mystery_reveal'] as $fn) {
    if (!preg_match('/function\\s+' . preg_quote($fn, '/') . '\\b.*?\\{\\s*coveted_event_require_system_admin\\(\\$actor\\);/s', $management)) {
        $failures[] = 'app/event_management.php missing System Admin guard in ' . $fn;
    }
}

if (preg_match('/function\\s+coveted_event_can_manage\\b.*?function\\s+coveted_event_can_checkin/s', $events, $match)
    && str_contains($match[0], 'coveted_event_group_role')) {
    $failures[] = 'coveted_event_can_manage still grants event control from a group role.';
}
if (str_contains($host, 'name="action" value="create_event"')) {
    $failures[] = 'Host Workspace still exposes event creation.';
}
if (str_contains($calendar, '>Create an Event<')) {
    $failures[] = 'Events calendar still tells hosts to create events.';
}
if (str_contains($group, 'Plan a gathering →')) {
    $failures[] = 'Group page still tells hosts to plan/create gatherings.';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Event authority contract OK\n";
''')

# Add verifier to permanent CI
p = Path(".github/workflows/php-lint.yml")
text = p.read_text()
marker = '          exit "$failed"\n'
if "Verify event authority contract" not in text:
    text = replace_once(
        text,
        marker,
        marker + "\n      - name: Verify event authority contract\n        run: php scripts/verify-event-authority.php\n",
        "add authority verifier to CI",
    )
p.write_text(text)
