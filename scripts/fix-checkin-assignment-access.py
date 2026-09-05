from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected one match, found {count}")
    return text.replace(old, new, 1)


p = Path("host.php")
text = p.read_text()
old = """$user = coveted_require_user();
$isSystemAdmin = coveted_is_system_admin($user);

if (!coveted_event_actor_has_host_approval($user)) {
"""
new = """$user = coveted_require_user();
$isSystemAdmin = coveted_is_system_admin($user);
$hasHostApproval = coveted_event_actor_has_host_approval($user);
$assignmentStmt = coveted_db()->prepare('SELECT 1 FROM event_hosts WHERE user_id = ? LIMIT 1');
$assignmentStmt->execute([(int)$user['id']]);
$hasEventAssignment = (bool)$assignmentStmt->fetchColumn();

if (!$hasHostApproval && !$hasEventAssignment) {
"""
text = replace_once(text, old, new, "host assignment entrance")
p.write_text(text)

p = Path("events.php")
text = p.read_text()
old = """$hostingEvents = array_merge($hostUpcoming, $hostHistory);
$hostingCount = count($hostingEvents);

"""
new = """$hostingEvents = array_merge($hostUpcoming, $hostHistory);
$hostingCount = count($hostingEvents);
$hasHostWorkspaceAccess = $isApprovedHost || $hostingCount > 0;

"""
text = replace_once(text, old, new, "calendar workspace access")
text = text.replace("$isApprovedHost ? '/events.php?view=hosting' : '/events.php?view=history'", "$hasHostWorkspaceAccess ? '/events.php?view=hosting' : '/events.php?view=history'")
text = text.replace("$isApprovedHost ? $hostingCount : count($historyEvents)", "$hasHostWorkspaceAccess ? $hostingCount : count($historyEvents)")
text = text.replace("$isApprovedHost ? 'Hosting' : 'History'", "$hasHostWorkspaceAccess ? 'Hosting' : 'History'")
text = text.replace("<?php if ($isApprovedHost): ?>\n            <a class=\"cv-button cv-button-soft\" href=\"/host.php\">Host Workspace</a>", "<?php if ($hasHostWorkspaceAccess): ?>\n            <a class=\"cv-button cv-button-soft\" href=\"/host.php\">Host Workspace</a>")
text = text.replace("<?php if ($isApprovedHost): ?>\n        <a class=\"cv-tab <?= $view === 'hosting' ? 'is-active' : '' ?>\" href=\"/events.php?view=hosting\">Hosting</a>", "<?php if ($hasHostWorkspaceAccess): ?>\n        <a class=\"cv-tab <?= $view === 'hosting' ? 'is-active' : '' ?>\" href=\"/events.php?view=hosting\">Hosting</a>")
text = text.replace("<?php if ($view === 'hosting' && $isApprovedHost): ?><a class=\"cv-button\" href=\"/host.php\">Open Host Workspace</a><?php endif; ?>", "<?php if ($view === 'hosting' && $hasHostWorkspaceAccess): ?><a class=\"cv-button\" href=\"/host.php\">Open Host Workspace</a><?php endif; ?>")
p.write_text(text)

p = Path("scripts/verify-event-authority.php")
text = p.read_text()
marker = "    ['host.php', $host, 'No assigned events yet.'],\n"
addition = "    ['host.php', $host, '$hasEventAssignment = (bool)$assignmentStmt->fetchColumn();'],\n    ['events.php', $calendar, '$hasHostWorkspaceAccess = $isApprovedHost || $hostingCount > 0;'],\n"
if addition not in text:
    text = replace_once(text, marker, marker + addition, "verifier check-in contract")
p.write_text(text)
