<?php
declare(strict_types=1);

require_once __DIR__ . '/app/host_command.php';

$user = coveted_require_user();
$pdo = coveted_db();
$error = '';
$notice = '';
$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if ($eventRef === '') throw new InvalidArgumentException('Choose an assigned event first.');
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'task_status') {
            coveted_host_command_update_task($user, $eventRef, (string)($_POST['item_ref'] ?? ''), (string)($_POST['status'] ?? ''), $pdo);
            $notice = 'Production task updated.';
        } elseif ($action === 'attendance') {
            coveted_host_command_record_attendance($user, $eventRef, (int)($_POST['user_id'] ?? 0), (string)($_POST['status'] ?? 'checked_in'));
            $notice = 'Attendance updated.';
        } elseif ($action === 'note') {
            coveted_host_command_add_note($user, $eventRef, (string)($_POST['note_type'] ?? 'incident'), (string)($_POST['body'] ?? ''), $pdo);
            $notice = 'Host note added to Event Production.';
        } else {
            throw new InvalidArgumentException('Unsupported Host Command action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Host Command Center action failed: ' . $e->getMessage());
        $error = 'Unable to update Host Command right now.';
    }
}

$events = coveted_host_command_events($user, 80, $pdo);
if ($eventRef === '' && $events) {
    $now = time();
    $candidate = null;
    foreach ($events as $event) {
        $start = strtotime((string)$event['starts_at']) ?: 0;
        if ($start >= $now - 86400 && !in_array((string)$event['status'], ['completed','cancelled'], true)) {
            $candidate = $event;
            break;
        }
    }
    $eventRef = (string)(($candidate ?? $events[0])['public_id'] ?? '');
}

$snapshot = null;
if ($eventRef !== '') {
    try {
        $snapshot = coveted_host_command_snapshot($user, $eventRef, $pdo);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        $eventRef = '';
    }
}

$formatLocal = static function (array $event, string $field = 'starts_at', string $format = 'D, M j · g:i A T'): string {
    try { return coveted_event_format($event, $format, $field); } catch (Throwable) { return (string)($event[$field] ?? ''); }
};

coveted_page_start('Host Command', 'Events');
?>
<div class="cv-host-command-shell">
    <header class="cv-host-command-head">
        <div>
            <span class="cv-eyebrow">HOST COMMAND CENTER</span>
            <h1><?= $snapshot ? coveted_e((string)$snapshot['event']['title']) : 'Your assigned events' ?></h1>
            <p><?= $snapshot ? 'Run the event. Keep attendance, assigned production work and meaningful escalations current.' : 'Choose an assigned gathering to open its event-day command view.' ?></p>
        </div>
        <div class="cv-host-command-head-actions">
            <a class="cv-button cv-button-soft" href="/host.php<?= $eventRef !== '' ? '?event='.coveted_e(rawurlencode($eventRef)) : '' ?>">Host Workspace</a>
            <?php if (coveted_is_system_admin($user) && $eventRef !== ''): ?><a class="cv-button cv-button-soft" href="/admin/event-production.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>">Admin Production</a><?php endif; ?>
        </div>
    </header>

    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

    <?php if ($events): ?>
        <nav class="cv-host-command-event-strip" aria-label="Assigned events">
            <?php foreach ($events as $event): ?>
                <a class="<?= $eventRef === (string)$event['public_id'] ? 'is-active' : '' ?>" href="/host-command.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">
                    <strong><?= coveted_e((string)$event['title']) ?></strong>
                    <small><?= coveted_e($formatLocal($event, 'starts_at', 'M j · g:i A')) ?> · <?= coveted_e((string)$event['status']) ?></small>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($snapshot): ?>
        <?php $event=(array)$snapshot['event']; $metrics=(array)$snapshot['metrics']; $permissions=(array)$snapshot['permissions']; $location=(array)($snapshot['location']??[]); ?>
        <section class="cv-host-command-status">
            <div><span>Role</span><strong><?= coveted_e(ucwords(str_replace('_',' ',(string)$snapshot['role']))) ?></strong></div>
            <div><span>Attending</span><strong><?= (int)$metrics['attending'] ?></strong></div>
            <div><span>Arrived</span><strong><?= (int)$metrics['arrived'] ?></strong></div>
            <div><span>Waitlist</span><strong><?= (int)$metrics['waitlist'] ?></strong></div>
            <div><span>Open tasks</span><strong><?= (int)$metrics['open_tasks'] ?></strong></div>
            <div><span>Blocked</span><strong><?= (int)$metrics['blocked_tasks'] ?></strong></div>
        </section>

        <section class="cv-host-command-event-card">
            <div class="cv-host-command-event-meta">
                <span class="cv-status"><?= coveted_e(ucfirst((string)$event['status'])) ?></span>
                <strong><?= coveted_e($formatLocal($event)) ?></strong>
                <span><?= coveted_e((string)$event['group_name']) ?></span>
            </div>
            <div class="cv-host-command-location">
                <span class="cv-eyebrow">LOCATION</span>
                <strong><?= coveted_e((string)($location['location_name'] ?? $location['private_location_label'] ?? 'Location pending')) ?></strong>
                <?php if (!empty($location['business_name'])): ?><span><?= coveted_e((string)$location['business_name']) ?></span><?php endif; ?>
                <?php if (!empty($location['address1'])): ?><span><?= coveted_e(trim((string)$location['address1'].' · '.(string)$location['city'].', '.(string)$location['region'].' '.(string)$location['postal_code'])) ?></span><?php endif; ?>
                <?php if (!empty($location['reveal_notes'])): ?><small><?= coveted_e((string)$location['reveal_notes']) ?></small><?php endif; ?>
            </div>
        </section>

        <div class="cv-host-command-grid">
            <section class="cv-host-command-panel">
                <div class="cv-host-command-panel-head"><div><span class="cv-eyebrow">RUN OF SHOW</span><h2>What happens next</h2></div></div>
                <?php $runNotes=array_values(array_filter((array)$snapshot['notes'],static fn(array $n):bool=>(string)$n['note_type']==='run_of_show')); ?>
                <?php if ($runNotes): ?>
                    <?php foreach (array_reverse($runNotes) as $note): ?><div class="cv-host-command-note"><strong><?= coveted_e((string)$note['author_name']) ?></strong><div><?= nl2br(coveted_e((string)$note['body'])) ?></div></div><?php endforeach; ?>
                <?php else: ?><div class="cv-empty"><strong>No run of show posted yet.</strong><span>System Admin can add one from Event Production or through the approved Event Playbook.</span></div><?php endif; ?>
            </section>

            <section class="cv-host-command-panel">
                <div class="cv-host-command-panel-head"><div><span class="cv-eyebrow">HOST TEAM</span><h2>Who is running this</h2></div></div>
                <div class="cv-host-command-host-list"><?php foreach ((array)$snapshot['hosts'] as $host): ?><div><span class="cv-avatar-fallback"><?= coveted_e(coveted_shell_initials((string)$host['display_name'])) ?></span><span><strong><?= coveted_e((string)$host['display_name']) ?></strong><small><?= coveted_e(ucfirst((string)$host['host_role'])) ?></small></span></div><?php endforeach; ?></div>
            </section>
        </div>

        <section class="cv-host-command-panel cv-host-command-section-gap">
            <div class="cv-host-command-panel-head"><div><span class="cv-eyebrow">PRODUCTION</span><h2>Event-day tasks</h2></div><span class="cv-pill"><?= (int)$metrics['my_open_tasks'] ?> assigned to you</span></div>
            <?php if (!coveted_event_production_schema_available($pdo)): ?><div class="cv-alert cv-alert-error">Event Production is not installed. Import the Event Opportunity / Production migration.</div><?php endif; ?>
            <div class="cv-host-command-task-list">
                <?php foreach ((array)$snapshot['items'] as $item): ?>
                    <?php if ((string)$item['phase']==='planning' && empty($item['mine'])) continue; ?>
                    <article class="cv-host-command-task <?= (string)$item['status']==='blocked'?'is-blocked':'' ?> <?= !empty($item['mine'])?'is-mine':'' ?>">
                        <div class="cv-host-command-task-copy"><span><?= coveted_e(ucwords(str_replace('_',' ',(string)$item['phase']))) ?> · <?= coveted_e(ucfirst((string)$item['item_type'])) ?></span><strong><?= coveted_e((string)$item['title']) ?></strong><?php if(!empty($item['detail'])):?><small><?= coveted_e((string)$item['detail']) ?></small><?php endif; ?><?php if(!empty($item['assigned_name'])):?><small>Assigned to <?= coveted_e((string)$item['assigned_name']) ?></small><?php endif; ?></div>
                        <span class="cv-status"><?= coveted_e(ucwords(str_replace('_',' ',(string)$item['status']))) ?></span>
                        <?php if (!empty($item['can_operate'])): ?><form method="post" class="cv-host-command-task-actions"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="task_status"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="item_ref" value="<?= coveted_e((string)$item['public_id']) ?>"><button name="status" value="in_progress">Working</button><button name="status" value="blocked">Block</button><button name="status" value="completed">Done</button></form><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!(array)$snapshot['items']): ?><div class="cv-empty"><strong>No Production plan yet.</strong><span>System Admin can load the baseline plan in Event Production.</span></div><?php endif; ?>
            </div>
        </section>

        <section class="cv-host-command-panel cv-host-command-section-gap">
            <div class="cv-host-command-panel-head"><div><span class="cv-eyebrow">GUEST COMMAND</span><h2>Arrivals & attendance</h2></div><span class="cv-pill"><?= (int)$metrics['arrived'] ?>/<?= (int)$metrics['attending'] ?> arrived</span></div>
            <div class="cv-host-command-guest-list">
                <?php foreach ((array)$snapshot['people'] as $person): ?>
                    <?php if (!in_array((string)($person['response']??''),['attending','waitlist'],true) && empty($person['attendance_status'])) continue; ?>
                    <article class="cv-host-command-guest">
                        <div><strong><?= coveted_e((string)$person['display_name']) ?></strong><small><?= coveted_e(ucfirst((string)($person['response'] ?: $person['invitation_status'] ?: 'participant'))) ?><?= (int)($person['guest_count']??0)>0?' · +1':'' ?></small></div>
                        <span class="cv-status"><?= coveted_e((string)($person['attendance_status'] ? ucwords(str_replace('_',' ',(string)$person['attendance_status'])) : 'Not arrived')) ?></span>
                        <?php if (!empty($permissions['attendance']) && (string)($person['response']??'')==='attending'): ?><form method="post" class="cv-host-command-guest-actions"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="attendance"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><button name="status" value="checked_in">Check in</button><button name="status" value="left_early">Left early</button><button name="status" value="no_show">No show</button></form><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="cv-host-command-grid cv-host-command-section-gap">
            <section class="cv-host-command-panel">
                <div class="cv-host-command-panel-head"><div><span class="cv-eyebrow">ESCALATION</span><h2>Log an incident</h2></div><span class="cv-pill">Agent-visible</span></div>
                <?php if (!empty($permissions['incident_notes'])): ?><form method="post" class="cv-host-command-note-form"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="note"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="note_type" value="incident"><textarea name="body" rows="4" placeholder="Only record meaningful venue, safety, guest or operational issues." required></textarea><button class="cv-button cv-button-primary">Log Incident</button></form><?php endif; ?>
                <?php foreach (array_filter((array)$snapshot['notes'],static fn(array $n):bool=>(string)$n['note_type']==='incident') as $note): ?><div class="cv-host-command-note is-incident"><strong><?= coveted_e((string)$note['author_name']) ?></strong><small><?= coveted_e((string)$note['created_at']) ?></small><div><?= nl2br(coveted_e((string)$note['body'])) ?></div></div><?php endforeach; ?>
            </section>
            <section class="cv-host-command-panel">
                <div class="cv-host-command-panel-head"><div><span class="cv-eyebrow">CLOSEOUT</span><h2>Host handoff</h2></div></div>
                <?php if (in_array((string)$snapshot['role'],['lead','cohost','system_admin'],true)): ?><form method="post" class="cv-host-command-note-form"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="note"><input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>"><input type="hidden" name="note_type" value="closeout"><textarea name="body" rows="4" placeholder="What should Admin know after this event? Partner feedback, guest flow, wins, problems, next steps." required></textarea><button class="cv-button cv-button-soft">Add Closeout Note</button></form><?php endif; ?>
                <?php foreach (array_filter((array)$snapshot['notes'],static fn(array $n):bool=>(string)$n['note_type']==='closeout') as $note): ?><div class="cv-host-command-note"><strong><?= coveted_e((string)$note['author_name']) ?></strong><small><?= coveted_e((string)$note['created_at']) ?></small><div><?= nl2br(coveted_e((string)$note['body'])) ?></div></div><?php endforeach; ?>
            </section>
        </div>

        <?php if (empty($permissions['event_configuration'])): ?><div class="cv-alert cv-host-command-authority"><strong>Host operating boundary:</strong> You can run assigned event-day work, attendance and escalations here. Event dates, venue, capacity, artists, Playbooks, proposals, benefits and host assignments remain controlled by Coveted System Admin.</div><?php endif; ?>
    <?php elseif (!$events): ?>
        <div class="cv-card cv-empty"><h2>No assigned events.</h2><p>Host Command appears when Coveted System Admin assigns you to an event.</p><a class="cv-button" href="/host.php">Open Host Workspace</a></div>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
