<?php
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
$memberPages = $read('app/member_pages_v2.php');
$host = $read('host.php');
$calendar = $read('events.php');
$group = $read('group.php');

$mustContain = [
    ['app/events.php', $events, 'function coveted_event_require_system_admin(array $actor): void'],
    ['app/events.php', $events, "function coveted_event_create(array \$actor, int \$groupId, array \$data): array\n{\n    coveted_event_require_system_admin(\$actor);"],
    ['app/events.php', $events, "function coveted_event_set_status(array \$actor, string \$eventRef, string \$status): void\n{\n    coveted_event_require_system_admin(\$actor);"],
    ['app/events.php', $events, "function coveted_event_assign_host(array \$actor, string \$eventRef, int \$userId, string \$hostRole): void\n{\n    coveted_event_require_system_admin(\$actor);"],
    ['host.php', $host, '$canConfigure = $selectedEvent && $isSystemAdmin && !$isFinalEvent;'],
    ['host.php', $host, 'No assigned events yet.'],
    ['host.php', $host, '$hasEventAssignment = (bool)$assignmentStmt->fetchColumn();'],
    ['events.php', $calendar, '$hasHostWorkspaceAccess = !$sampleMode && ($isApprovedHost || $hostingCount > 0);'],
    ['events.php', $calendar, "'hosting' => 'No host assignments right now.'"],
    ['app/member_pages_v2.php', $memberPages, "in_array(\$assignedRole, ['lead', 'cohost'], true)"],
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
