<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_production.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$eventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$error = '';
$notice = !empty($_GET['created']) ? 'Recommended event draft created with its production plan.' : '';

$event = $eventRef !== '' ? coveted_event_by_ref($eventRef) : null;
if (!$event) {
    http_response_code(404);
    coveted_page_start('Event Production', '', true);
    coveted_admin_ui_start($admin, 'events', 'Event Production');
    ?><div class="cv-admin-page-head"><div><span class="cv-eyebrow">EVENT PRODUCTION</span><h1>Event not found.</h1></div><a class="cv-button cv-button-soft" href="/admin/?view=events">← All Events</a></div><?php
    coveted_admin_ui_end(); coveted_page_end(); exit;
}
$eventRef = (string)$event['public_id'];

$localToUtc = static function (string $value, string $timezone): string {
    $value = trim($value);
    if ($value === '') return '';
    $zone = coveted_require_timezone($timezone);
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
    if (!$local || $local->format('Y-m-d\TH:i') !== $value) throw new InvalidArgumentException('Enter a valid production due date/time.');
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        switch ($action) {
            case 'seed_defaults':
                $created = coveted_event_production_seed_defaults($admin, $eventRef, $pdo);
                $notice = $created > 0 ? $created . ' baseline production items created.' : 'This event already has a production plan.';
                break;
            case 'create_item':
                coveted_event_production_create_item($admin, $eventRef, [
                    'phase'=>(string)($_POST['phase'] ?? 'planning'),
                    'item_type'=>(string)($_POST['item_type'] ?? 'task'),
                    'priority'=>(string)($_POST['priority'] ?? 'normal'),
                    'title'=>(string)($_POST['title'] ?? ''),
                    'detail'=>(string)($_POST['detail'] ?? ''),
                    'assigned_user_id'=>(int)($_POST['assigned_user_id'] ?? 0),
                    'due_at'=>$localToUtc((string)($_POST['due_at'] ?? ''), (string)$event['timezone']),
                ], $pdo);
                $notice = 'Production item added.';
                break;
            case 'update_item':
                coveted_event_production_update_item($admin, $eventRef, (string)($_POST['item_ref'] ?? ''), [
                    'status'=>(string)($_POST['status'] ?? 'open'),
                    'assigned_user_id'=>(int)($_POST['assigned_user_id'] ?? 0),
                ], $pdo);
                $notice = 'Production item updated.';
                break;
            case 'add_note':
                coveted_event_production_add_note($admin, $eventRef, (string)($_POST['note_type'] ?? 'general'), (string)($_POST['body'] ?? ''), $pdo);
                $notice = 'Production note added.';
                break;
            default:
                throw new InvalidArgumentException('Unsupported Event Production action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Production update failed: ' . $e->getMessage());
        $error = 'Unable to update Event Production right now.';
    }
}

$snapshot = coveted_event_production_snapshot($admin, $eventRef, $pdo);
$event = (array)$snapshot['event'];
$hostsStmt = $pdo->prepare("SELECT eh.user_id, eh.host_role, u.display_name FROM event_hosts eh JOIN users u ON u.id = eh.user_id WHERE eh.event_id = ? AND u.status = 'active' ORDER BY FIELD(eh.host_role,'lead','cohost','checkin'), u.display_name");
$hostsStmt->execute([(int)$event['id']]);
$eventHosts = $hostsStmt->fetchAll();

$formatTime = static function (?string $value, string $timezone): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    try { return coveted_utc_datetime($value)->setTimezone(coveted_timezone($timezone))->format('M j · g:i A T'); }
    catch (Throwable) { return $value; }
};
$phaseLabels = ['planning'=>'Planning','pre_event'=>'Pre-event','arrival'=>'Arrival','live'=>'Live','closeout'=>'Closeout'];

coveted_page_start('Event Production', '', true);
coveted_admin_ui_start($admin, 'events', 'Event Production');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">EVENT PRODUCTION WORKSPACE</span>
        <h1><?= coveted_e((string)$event['title']) ?></h1>
        <p><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e($formatTime((string)$event['starts_at'], (string)$event['timezone'])) ?></p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>">Event Workspace</a>
        <a class="cv-button cv-button-soft" href="/admin/event-opportunities.php">Event Opportunities</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if (empty($snapshot['schema_available'])): ?>
    <div class="cv-alert cv-alert-error"><strong>Event Production migration required.</strong> Import <code>database/migrations/20260906_event_opportunity_production.sql</code> before using production tasks or notes.</div>
<?php endif; ?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Readiness</span><strong><?= (int)$snapshot['readiness'] ?>%</strong><small>Required production checks</small></div>
    <div><span>Open items</span><strong><?= (int)$snapshot['production']['open'] ?></strong><small>Open / in progress</small></div>
    <div><span>Blocked</span><strong><?= (int)$snapshot['production']['blocked'] ?></strong><small>Needs Admin attention</small></div>
    <div><span>Attending</span><strong><?= (int)$snapshot['canonical']['attending_count'] ?></strong><small><?= (int)$snapshot['canonical']['verified_attendance'] ?> verified attendance</small></div>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">READINESS</span><h2>Production checks</h2></div><span class="cv-status"><?= (int)$snapshot['readiness'] ?>%</span></div>
        <div class="cv-admin-list">
            <?php foreach ((array)$snapshot['checks'] as $check): ?>
                <div class="cv-admin-list-row">
                    <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$check['label']) ?></strong><small><?= !empty($check['required']) ? 'Required' : 'Optional / event dependent' ?></small></span>
                    <span class="cv-status"><?= !empty($check['done']) ? 'Ready' : 'Needs attention' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($snapshot['schema_available']) && (int)$snapshot['production']['total'] === 0): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="seed_defaults">
                <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                <button class="cv-button cv-button-primary" type="submit">Load Baseline Production Plan</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LIVE STATE</span><h2>Event operations</h2></div></div>
        <dl class="cv-admin-event-definition-list">
            <div><dt>Hosts</dt><dd><?= (int)$snapshot['canonical']['host_count'] ?></dd></div>
            <div><dt>Lead host</dt><dd><?= (int)$snapshot['canonical']['lead_host_count'] > 0 ? 'Assigned' : 'Missing' ?></dd></div>
            <div><dt>Location</dt><dd><?= (int)$snapshot['canonical']['location_count'] > 0 ? 'Configured' : 'Missing' ?></dd></div>
            <div><dt>Artists</dt><dd><?= (int)$snapshot['canonical']['artist_count'] ?></dd></div>
            <div><dt>Benefits</dt><dd><?= (int)$snapshot['canonical']['campaign_count'] ?> linked</dd></div>
            <div><dt>Invited</dt><dd><?= (int)$snapshot['canonical']['invite_count'] ?></dd></div>
            <div><dt>Attending</dt><dd><?= (int)$snapshot['canonical']['attending_count'] ?></dd></div>
            <div><dt>Waitlist</dt><dd><?= (int)$snapshot['canonical']['waitlist_count'] ?></dd></div>
        </dl>
    </section>
</div>

<?php if (!empty($snapshot['schema_available'])): ?>
    <?php foreach ($phaseLabels as $phaseKey => $phaseLabel): ?>
        <?php $phaseItems = array_values(array_filter((array)$snapshot['items'], static fn(array $row): bool => (string)$row['phase'] === $phaseKey && (string)$row['status'] !== 'cancelled')); ?>
        <section class="cv-admin-panel cv-admin-section-gap">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PHASE</span><h2><?= coveted_e($phaseLabel) ?></h2></div><span class="cv-pill"><?= count($phaseItems) ?> items</span></div>
            <?php if (!$phaseItems): ?><div class="cv-admin-empty"><strong>No <?= coveted_e(strtolower($phaseLabel)) ?> items.</strong></div><?php endif; ?>
            <div class="cv-admin-list">
                <?php foreach ($phaseItems as $item): ?>
                    <form method="post" class="cv-admin-list-row">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_item">
                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                        <input type="hidden" name="item_ref" value="<?= coveted_e((string)$item['public_id']) ?>">
                        <span class="cv-admin-list-copy">
                            <strong><?= coveted_e((string)$item['title']) ?></strong>
                            <small><?= coveted_e(ucfirst((string)$item['item_type'])) ?> · <?= coveted_e(ucfirst((string)$item['priority'])) ?><?= !empty($item['due_at']) ? ' · Due ' . coveted_e($formatTime((string)$item['due_at'], (string)$event['timezone'])) : '' ?></small>
                            <?php if (!empty($item['detail'])): ?><small><?= coveted_e((string)$item['detail']) ?></small><?php endif; ?>
                        </span>
                        <span>
                            <select name="assigned_user_id" aria-label="Assign host">
                                <option value="0">Unassigned</option>
                                <?php foreach ($eventHosts as $host): ?><option value="<?= (int)$host['user_id'] ?>" <?= (int)($item['assigned_user_id'] ?? 0) === (int)$host['user_id'] ? 'selected' : '' ?>><?= coveted_e((string)$host['display_name']) ?> · <?= coveted_e((string)$host['host_role']) ?></option><?php endforeach; ?>
                            </select>
                            <select name="status" aria-label="Production status">
                                <?php foreach (coveted_event_production_statuses() as $status): ?><option value="<?= coveted_e($status) ?>" <?= (string)$item['status'] === $status ? 'selected' : '' ?>><?= coveted_e(ucwords(str_replace('_',' ',$status))) ?></option><?php endforeach; ?>
                            </select>
                            <button class="cv-button cv-button-soft" type="submit">Save</button>
                        </span>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ADD ITEM</span><h2>Production task</h2></div></div>
            <form method="post" class="cv-form-grid">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_item">
                <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                <label class="cv-form-span">Title<input name="title" maxlength="255" required></label>
                <label>Phase<select name="phase"><?php foreach ($phaseLabels as $key=>$label): ?><option value="<?= coveted_e($key) ?>"><?= coveted_e($label) ?></option><?php endforeach; ?></select></label>
                <label>Type<select name="item_type"><?php foreach (coveted_event_production_item_types() as $type): ?><option value="<?= coveted_e($type) ?>"><?= coveted_e(ucfirst($type)) ?></option><?php endforeach; ?></select></label>
                <label>Priority<select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="critical">Critical</option><option value="low">Low</option></select></label>
                <label>Assign host<select name="assigned_user_id"><option value="0">Unassigned</option><?php foreach ($eventHosts as $host): ?><option value="<?= (int)$host['user_id'] ?>"><?= coveted_e((string)$host['display_name']) ?> · <?= coveted_e((string)$host['host_role']) ?></option><?php endforeach; ?></select></label>
                <label>Due<input type="datetime-local" name="due_at"></label>
                <label class="cv-form-span">Detail<textarea name="detail" rows="4" maxlength="5000"></textarea></label>
                <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Add Production Item</button></div>
            </form>
        </section>

        <section class="cv-admin-panel">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LOG</span><h2>Add production note</h2></div></div>
            <form method="post" class="cv-form-grid">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                <label>Type<select name="note_type"><option value="general">General</option><option value="run_of_show">Run of show</option><option value="venue">Venue</option><option value="artist">Artist</option><option value="guest">Guest</option><option value="incident">Incident</option><option value="closeout">Closeout</option></select></label>
                <label class="cv-form-span">Note<textarea name="body" rows="6" maxlength="8000" required></textarea></label>
                <div class="cv-form-span"><button class="cv-button cv-button-primary" type="submit">Add Note</button></div>
            </form>
        </section>
    </div>

    <section class="cv-admin-panel cv-admin-section-gap">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PRODUCTION HISTORY</span><h2>Notes & run-of-show record</h2></div></div>
        <div class="cv-admin-list">
            <?php if (empty($snapshot['notes'])): ?><div class="cv-admin-empty"><strong>No production notes yet.</strong></div><?php endif; ?>
            <?php foreach ((array)$snapshot['notes'] as $note): ?>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e(ucwords(str_replace('_',' ',(string)$note['note_type']))) ?></strong><small><?= coveted_e((string)$note['author_name']) ?> · <?= coveted_e($formatTime((string)$note['created_at'], (string)$event['timezone'])) ?></small><small><?= nl2br(coveted_e((string)$note['body'])) ?></small></span></div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY</span><h2>Admin plans. Hosts operate.</h2></div></div>
    <p>This workspace coordinates execution without changing Coveted's event-authority model. Only System Admin can create/configure events and manage this production plan. Assigned hosts continue to operate through the Host / Check-in workflow.</p>
</section>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
