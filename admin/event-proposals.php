<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_proposals.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = !empty($_GET['created']) ? 'Event Proposal created from the opportunity.' : '';
$proposalRef = trim((string)($_GET['proposal'] ?? $_POST['proposal_ref'] ?? ''));

$localToUtc = static function (string $value, string $timezone): string {
    $value = trim($value);
    if ($value === '') return '';
    $zone = coveted_require_timezone($timezone);
    $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
    if (!$local || $local->format('Y-m-d\TH:i') !== $value) throw new InvalidArgumentException('Enter a valid proposal date and time.');
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($proposalRef === '') throw new InvalidArgumentException('Choose an Event Proposal.');
        if ($action === 'update') {
            $current = coveted_event_proposal_by_ref($admin, $proposalRef, $pdo);
            if (!$current) throw new InvalidArgumentException('Event Proposal not found.');
            $_POST['proposed_start_at'] = $localToUtc((string)($_POST['proposed_start_local'] ?? ''), (string)$current['timezone']);
            $_POST['alternate_start_at'] = $localToUtc((string)($_POST['alternate_start_local'] ?? ''), (string)$current['timezone']);
            coveted_event_proposal_update($admin, $proposalRef, $_POST, $pdo);
            $notice = 'Proposal details updated.';
        } elseif ($action === 'status') {
            coveted_event_proposal_set_status($admin, $proposalRef, (string)($_POST['status'] ?? ''), $pdo);
            $notice = 'Proposal workflow updated.';
        } elseif ($action === 'add_update') {
            coveted_event_proposal_add_update($admin, $proposalRef, $_POST, $pdo);
            $notice = 'Proposal activity recorded.';
        } elseif ($action === 'approve') {
            coveted_event_proposal_approve($admin, $proposalRef, $pdo);
            $notice = 'Proposal approved.';
        } elseif ($action === 'convert') {
            $result = coveted_event_proposal_convert($admin, $proposalRef, $pdo);
            coveted_redirect('/admin/event-production.php?event=' . rawurlencode((string)$result['event']['public_id']) . '&created=1');
        } else {
            throw new InvalidArgumentException('Unsupported Event Proposal action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Event Proposal workspace failed: ' . $e->getMessage());
        $error = 'Unable to update that Event Proposal right now.';
    }
}

$ready = coveted_event_proposal_schema_available($pdo);
$proposals = $ready ? coveted_event_proposals($admin, '', $pdo) : [];
$selected = $proposalRef !== '' ? coveted_event_proposal_by_ref($admin, $proposalRef, $pdo) : ($proposals[0] ?? null);
if ($selected) $proposalRef = (string)$selected['public_id'];
$updates = $selected ? coveted_event_proposal_updates($admin, $proposalRef, $pdo) : [];
$playbooks = $ready ? coveted_event_playbooks($admin, false, $pdo) : [];
$contacts = [];
if ($selected && coveted_partner_crm_schema_available($pdo)) {
    try { $contacts = coveted_partner_contacts($admin, (int)$selected['business_id'], (string)$selected['group_ref'], (string)$selected['location_ref']); } catch (Throwable) {}
}
$artists = $pdo->query("SELECT id,public_id,artist_name FROM artist_profiles WHERE status='active' ORDER BY artist_name,id")->fetchAll();
$statusCounts = [];
foreach ($proposals as $p) $statusCounts[(string)$p['status']] = ($statusCounts[(string)$p['status']] ?? 0) + 1;
$fmt = static function (?string $utc, string $tz): string {
    if (!$utc) return 'Not set';
    try { return coveted_utc_datetime($utc)->setTimezone(coveted_timezone($tz))->format('M j, Y · g:i A T'); } catch (Throwable) { return $utc; }
};
$localInput = static function (?string $utc, string $tz): string {
    if (!$utc) return '';
    try { return coveted_utc_datetime($utc)->setTimezone(coveted_timezone($tz))->format('Y-m-d\TH:i'); } catch (Throwable) { return ''; }
};

coveted_page_start('Event Proposals', '', true);
coveted_admin_ui_start($admin, 'event-proposals', 'Event Proposals');
?>
<div class="cv-admin-page-head">
    <div><span class="cv-eyebrow">EVENT PROPOSALS</span><h1>Turn opportunities into agreed events.</h1><p>Work the concept, partner terms and Playbook before a System Admin converts anything into a canonical Event.</p></div>
    <div class="cv-admin-event-top-actions"><a class="cv-button cv-button-soft" href="/admin/event-opportunities.php">Opportunities</a><a class="cv-button cv-button-soft" href="/admin/event-playbooks.php">Playbooks</a><a class="cv-button cv-button-soft" href="/admin/?view=events">Events</a></div>
</div>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="cv-alert cv-alert-error"><strong>Migration required.</strong> Import the Event Proposals + Playbooks migration first.</div><?php endif; ?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Active pipeline</span><strong><?= count(array_filter($proposals, static fn($p) => !in_array((string)$p['status'], ['converted','declined'], true))) ?></strong><small>Planning / partner workflow</small></div>
    <div><span>Negotiating</span><strong><?= ($statusCounts['negotiating'] ?? 0) + ($statusCounts['partner_contacted'] ?? 0) ?></strong><small>Partner discussion</small></div>
    <div><span>Approved</span><strong><?= $statusCounts['approved'] ?? 0 ?></strong><small>Ready to convert</small></div>
    <div><span>Converted</span><strong><?= $statusCounts['converted'] ?? 0 ?></strong><small>Canonical Events created</small></div>
</div>
<?php if ($ready): ?>
<div class="cv-admin-dashboard-grid cv-admin-section-gap">
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PIPELINE</span><h2>Proposals</h2></div></div>
        <div class="cv-admin-list">
            <?php foreach ($proposals as $p): ?>
                <a class="cv-admin-list-row" href="/admin/event-proposals.php?proposal=<?= coveted_e(rawurlencode((string)$p['public_id'])) ?>">
                    <span class="cv-admin-list-copy"><strong><?= coveted_e((string)$p['title']) ?></strong><small><?= coveted_e((string)$p['group_name']) ?> · <?= coveted_e((string)$p['business_name']) ?> · <?= coveted_e((string)$p['playbook_name']) ?></small><small><?= coveted_e($fmt((string)($p['proposed_start_at'] ?? ''), (string)$p['timezone'])) ?></small></span>
                    <span class="cv-status"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$p['status']))) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!$proposals): ?><div class="cv-admin-empty"><strong>No proposals yet.</strong><span>Create one from Event Opportunities.</span></div><?php endif; ?>
        </div>
    </section>

    <?php if ($selected): ?>
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">WORKING PROPOSAL</span><h2><?= coveted_e((string)$selected['title']) ?></h2></div><span class="cv-status"><?= coveted_e(ucwords(str_replace('_',' ',(string)$selected['status']))) ?></span></div>
        <dl class="cv-admin-event-definition-list">
            <div><dt>Group</dt><dd><?= coveted_e((string)$selected['group_name']) ?></dd></div>
            <div><dt>Partner</dt><dd><?= coveted_e((string)$selected['business_name']) ?> · <?= coveted_e((string)$selected['location_name']) ?></dd></div>
            <div><dt>Playbook</dt><dd><?= coveted_e((string)$selected['playbook_name']) ?></dd></div>
            <div><dt>Proposed start</dt><dd><?= coveted_e($fmt((string)($selected['proposed_start_at'] ?? ''), (string)$selected['timezone'])) ?></dd></div>
            <div><dt>Expected</dt><dd><?= (int)$selected['expected_attendance'] ?> / <?= (int)$selected['capacity'] ?></dd></div>
            <div><dt>Activity</dt><dd><?= coveted_e($fmt((string)($selected['last_activity_at'] ?? $selected['updated_at']), (string)$selected['timezone'])) ?></dd></div>
        </dl>
        <?php $locked = in_array((string)$selected['status'], ['converted','declined'], true); ?>
        <?php if (!$locked): ?>
        <form method="post" class="cv-admin-form-grid">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="update"><input type="hidden" name="proposal_ref" value="<?= coveted_e($proposalRef) ?>">
            <label>Title<input name="title" value="<?= coveted_e((string)$selected['title']) ?>" required></label>
            <label>Playbook<select name="playbook_ref"><?php foreach ($playbooks as $pb): ?><option value="<?= coveted_e((string)$pb['public_id']) ?>" <?= (int)$pb['id'] === (int)$selected['playbook_id'] ? 'selected' : '' ?>><?= coveted_e((string)$pb['name']) ?></option><?php endforeach; ?></select></label>
            <label>Preferred date/time<input type="datetime-local" name="proposed_start_local" value="<?= coveted_e($localInput((string)($selected['proposed_start_at'] ?? ''), (string)$selected['timezone'])) ?>" required></label>
            <label>Alternate date/time<input type="datetime-local" name="alternate_start_local" value="<?= coveted_e($localInput((string)($selected['alternate_start_at'] ?? ''), (string)$selected['timezone'])) ?>"></label>
            <label>Expected attendance<input type="number" min="1" name="expected_attendance" value="<?= (int)$selected['expected_attendance'] ?>"></label>
            <label>Capacity<input type="number" min="1" name="capacity" value="<?= (int)$selected['capacity'] ?>"></label>
            <label>Partner contact<select name="partner_contact_ref"><option value="">No specific contact</option><?php foreach ($contacts as $c): ?><option value="<?= coveted_e((string)$c['public_id']) ?>" <?= (string)$c['public_id'] === (string)$selected['partner_contact_ref'] ? 'selected' : '' ?>><?= coveted_e((string)$c['full_name']) ?> · <?= coveted_e((string)$c['role_title']) ?></option><?php endforeach; ?></select></label>
            <label>Artist Partner<select name="artist_id"><option value="0">None</option><?php foreach ($artists as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$selected['artist_id'] ? 'selected' : '' ?>><?= coveted_e((string)$a['artist_name']) ?></option><?php endforeach; ?></select></label>
            <label class="cv-admin-form-span">Concept<textarea name="concept" rows="3"><?= coveted_e((string)$selected['concept']) ?></textarea></label>
            <label class="cv-admin-form-span">Partner contribution<textarea name="partner_contribution" rows="2"><?= coveted_e((string)$selected['partner_contribution']) ?></textarea></label>
            <label class="cv-admin-form-span">Proposed perk / reward<textarea name="proposed_perk" rows="2"><?= coveted_e((string)$selected['proposed_perk']) ?></textarea></label>
            <label class="cv-admin-form-span">Special requirements<textarea name="special_requirements" rows="2"><?= coveted_e((string)$selected['special_requirements']) ?></textarea></label>
            <label class="cv-admin-form-span">Internal notes<textarea name="internal_notes" rows="3"><?= coveted_e((string)$selected['internal_notes']) ?></textarea></label>
            <button class="cv-button cv-button-primary" type="submit">Save Proposal</button>
        </form>
        <div class="cv-admin-actions cv-admin-section-gap">
            <form method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="proposal_ref" value="<?= coveted_e($proposalRef) ?>"><select name="status"><option value="reviewing">Reviewing</option><option value="partner_contacted">Partner Contacted</option><option value="negotiating">Negotiating</option><option value="idea">Idea</option><option value="declined">Declined</option></select><button class="cv-button cv-button-soft">Update Status</button></form>
            <?php if ((string)$selected['status'] !== 'approved'): ?>
                <form method="post" data-confirm="Approve this Event Proposal?"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="proposal_ref" value="<?= coveted_e($proposalRef) ?>"><button class="cv-button cv-button-primary">Approve Proposal</button></form>
            <?php else: ?>
                <form method="post" data-confirm="Convert this approved proposal into a canonical draft Event and seed its Playbook production plan?"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="convert"><input type="hidden" name="proposal_ref" value="<?= coveted_e($proposalRef) ?>"><button class="cv-button cv-button-primary">Convert to Draft Event</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php if ($selected): ?>
<div class="cv-admin-dashboard-grid cv-admin-section-gap">
    <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PARTNER CONVERSATION</span><h2>Log proposal activity</h2></div><span class="cv-pill">Feeds Partner CRM</span></div>
        <?php if (!$locked): ?><form method="post" class="cv-admin-form-grid"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="add_update"><input type="hidden" name="proposal_ref" value="<?= coveted_e($proposalRef) ?>"><label>Update type<select name="update_type"><option value="partner_contact">Partner Contact</option><option value="partner_feedback">Partner Feedback</option><option value="internal_note">Internal Note</option></select></label><label>Contact method<select name="interaction_type"><option value="email">Email</option><option value="call">Call</option><option value="text">Text</option><option value="meeting">Meeting</option><option value="in_person">In person</option><option value="other">Other</option></select></label><label>Contact<select name="contact_ref"><option value="">No specific contact</option><?php foreach ($contacts as $c): ?><option value="<?= coveted_e((string)$c['public_id']) ?>"><?= coveted_e((string)$c['full_name']) ?> · <?= coveted_e((string)$c['role_title']) ?></option><?php endforeach; ?></select></label><label class="cv-admin-form-span">Update<textarea name="body" rows="4" required></textarea></label><button class="cv-button cv-button-primary">Add to Proposal Timeline</button></form><?php endif; ?>
    </section>
    <section class="cv-admin-panel"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">TIMELINE</span><h2>Proposal activity</h2></div></div><div class="cv-admin-list"><?php foreach ($updates as $u): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e(ucwords(str_replace('_',' ',(string)$u['update_type']))) ?></strong><small><?= coveted_e((string)$u['body']) ?></small><small><?= coveted_e((string)$u['author_name']) ?> · <?= coveted_e((string)$u['created_at']) ?></small></span></div><?php endforeach; ?><?php if (!$updates): ?><div class="cv-admin-empty"><strong>No proposal activity yet.</strong><span>Log partner contact, feedback or an internal note.</span></div><?php endif; ?></div></section>
</div>
<?php endif; ?>
<?php endif; ?>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
