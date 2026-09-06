<?php
declare(strict_types=1);

require_once __DIR__ . '/app/partner_crm.php';
require_once __DIR__ . '/app/partner_opportunities.php';
require_once __DIR__ . '/app/admin_ui.php';

$user = coveted_require_user();
$isSystemAdmin = coveted_is_system_admin($user);
$error = '';
$notice = trim((string)($_SESSION['partner_profile_notice'] ?? ''));
unset($_SESSION['partner_profile_notice']);

$businessRef = trim((string)($_GET['business'] ?? $_POST['business'] ?? ''));
$groupRef = trim((string)($_GET['group'] ?? $_POST['group'] ?? ''));
$locationRef = trim((string)($_GET['location'] ?? $_POST['location'] ?? ''));

$business = null;
try {
    $business = coveted_business_resolve_context($user, $businessRef);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if (!$business) throw new InvalidArgumentException('Business access is required.');
        $businessId = (int)$business['id'];
        $action = trim((string)($_POST['action'] ?? ''));
        switch ($action) {
            case 'update_identity':
                coveted_partner_business_profile_update($user,$businessId,$_POST);
                $saved = 'Partner profile identity updated.';
                break;
            case 'save_crm_state':
                coveted_partner_crm_save_state($user,$businessId,$groupRef,$locationRef,$_POST);
                $saved = 'Partner relationship ownership updated.';
                break;
            case 'save_contact':
                coveted_partner_contact_save($user,$businessId,$groupRef,$locationRef,$_POST);
                $saved = 'Partner contact saved.';
                break;
            case 'add_note':
                coveted_partner_note_add($user,$businessId,$groupRef,$locationRef,$_POST);
                $saved = 'Partner note added.';
                break;
            case 'add_interaction':
                coveted_partner_interaction_add($user,$businessId,$groupRef,$locationRef,$_POST);
                $saved = 'Partner interaction logged.';
                break;
            case 'add_followup':
                coveted_partner_followup_add($user,$businessId,$groupRef,$locationRef,$_POST);
                $saved = 'Partner follow-up created.';
                break;
            case 'followup_status':
                coveted_partner_followup_set_status($user,$businessId,$groupRef,$locationRef,(string)($_POST['followup_ref']??''),(string)($_POST['status']??''));
                $saved = 'Partner follow-up updated.';
                break;
            default:
                throw new InvalidArgumentException('Unsupported Partner Profile action.');
        }
        $_SESSION['partner_profile_notice'] = $saved;
        coveted_redirect('/partner-profile.php?business='.rawurlencode((string)$business['public_id']).'&group='.rawurlencode($groupRef).'&location='.rawurlencode($locationRef));
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Partner Profile action failed: '.$e->getMessage());
        $error = 'Unable to save that Partner Profile change right now.';
    }
}

$snapshot = null;
$opportunities = [];
$adminUsers = [];
if ($business && $groupRef !== '' && $locationRef !== '') {
    try {
        $snapshot = coveted_partner_profile_snapshot($user,(int)$business['id'],$groupRef,$locationRef);
        $ops = coveted_partner_opportunities_for_business($user,(int)$business['id']);
        foreach ((array)$ops['recommendations'] as $item) {
            if ((string)($item['group_ref']??'') === (string)$snapshot['relationship']['group_public_id']
                && (string)($item['location_ref']??'') === (string)$snapshot['relationship']['location_public_id']) {
                $opportunities[] = $item;
            }
        }
        if ($isSystemAdmin && coveted_partner_crm_schema_available()) {
            $adminUsers = coveted_partner_crm_admin_users();
            $crmAgent = coveted_partner_crm_agent_context($user,20);
            $profileHref = '/partner-profile.php?business='.rawurlencode((string)$business['public_id']).'&group='.rawurlencode((string)$snapshot['relationship']['group_public_id']).'&location='.rawurlencode((string)$snapshot['relationship']['location_public_id']);
            foreach ((array)($crmAgent['recommendations']??[]) as $item) {
                if ((string)($item['href']??'') === $profileHref) $opportunities[] = $item;
            }
        }
    } catch (Throwable $e) {
        error_log('Partner Profile load failed: '.$e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load that Partner Profile right now.';
    }
}

if ($isSystemAdmin) {
    coveted_page_start('Partner Profile','',true);
    coveted_admin_ui_start($user,'businesses',$snapshot ? (string)$snapshot['business']['name'] : 'Partner Profile');
} else {
    coveted_page_start('Partner Profile');
}
?>
<link rel="stylesheet" href="/assets/css/partner-profile-v1.css?v=partner-profile-crm-20260906">

<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if (!$snapshot): ?>
<section class="cv-card cv-empty">
    <span class="cv-eyebrow">PARTNER PROFILE</span>
    <h1>Choose an established venue relationship.</h1>
    <p>Partner Profiles are created from a real Coveted group × business-location relationship.</p>
    <a class="cv-button cv-button-primary" href="/venue-relationships.php<?= $business ? '?business='.coveted_e(rawurlencode((string)$business['public_id'])) : '' ?>">Open Venue Relationships</a>
</section>
<?php
if ($isSystemAdmin) coveted_admin_ui_end();
coveted_page_end();
exit;
endif;

$biz = (array)$snapshot['business'];
$rel = (array)$snapshot['relationship'];
$crm = (array)$snapshot['crm'];
$contacts = (array)$snapshot['contacts'];
$notes = (array)$snapshot['notes'];
$interactions = (array)$snapshot['interactions'];
$followups = (array)$snapshot['followups'];
$events = (array)$snapshot['events'];
$daily = (array)$snapshot['daily_events'];
$perks = (array)$snapshot['perks'];
$timeline = (array)$snapshot['timeline'];
$openFollowups = array_values(array_filter($followups,static fn(array $f):bool=>(string)$f['status']==='open'));
$overdueFollowups = array_values(array_filter($openFollowups,static fn(array $f):bool=>strtotime((string)$f['due_at'])<time()));
$statusLabel = ucwords(str_replace('_',' ',(string)$rel['relationship_status']));
$logo = trim((string)($biz['logo_url']??''));
$cover = trim((string)($biz['cover_url']??''));
$initials = '';
foreach (preg_split('/\s+/u',trim((string)$biz['name']),-1,PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
    $initials .= mb_strtoupper(mb_substr($part,0,1));
    if (mb_strlen($initials)>=2) break;
}
$format = static function(?string $value): string {
    if (!$value) return '—';
    try { return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A'); }
    catch (Throwable) { return (string)$value; }
};
$profileQuery='business='.rawurlencode((string)$biz['public_id']).'&group='.rawurlencode((string)$rel['group_public_id']).'&location='.rawurlencode((string)$rel['location_public_id']);
?>

<div class="cv-partner-profile">
    <nav class="cv-partner-profile-nav" aria-label="Partner Profile sections">
        <a href="#overview">Overview</a><a href="#contacts">Contacts</a><a href="#followups">Follow-ups</a><a href="#events">Events</a><a href="#perks">Perks</a><a href="#opportunities">Opportunities</a><a href="#timeline">Timeline</a>
    </nav>

    <section id="overview" class="cv-partner-hero">
        <div class="cv-partner-cover <?= $cover===''?'is-empty':'' ?>"><?php if($cover!==''):?><img src="<?= coveted_e($cover) ?>" alt="" loading="eager"><?php endif;?></div>
        <div class="cv-partner-identity">
            <div class="cv-partner-logo"><?php if($logo!==''):?><img src="<?= coveted_e($logo) ?>" alt="<?= coveted_e((string)$biz['name']) ?>"><?php else:?><span><?= coveted_e($initials ?: 'C') ?></span><?php endif;?></div>
            <div class="cv-partner-name">
                <span class="cv-eyebrow">PARTNER PROFILE</span>
                <h1><?= coveted_e((string)$biz['name']) ?></h1>
                <p><?= coveted_e((string)$rel['location_name']) ?><?php if(trim((string)$rel['city'])!==''):?> · <?= coveted_e((string)$rel['city']) ?><?php endif;?> · <?= coveted_e((string)$rel['group_name']) ?></p>
            </div>
            <div class="cv-partner-state">
                <span class="cv-status"><?= coveted_e($statusLabel) ?></span>
                <?php if(!empty($rel['partner_since'])):?><small>Partner since <?= coveted_e($format((string)$rel['partner_since'])) ?></small><?php endif;?>
                <?php if($isSystemAdmin && trim((string)($crm['owner_name']??''))!==''):?><small>Owner · <?= coveted_e((string)$crm['owner_name']) ?></small><?php endif;?>
            </div>
        </div>
        <div class="cv-partner-actions">
            <a class="cv-button cv-button-soft" href="/venue-relationships.php?<?= coveted_e($profileQuery) ?>">Manage Relationship</a>
            <a class="cv-button cv-button-soft" href="/partner-perks.php?<?= coveted_e($profileQuery) ?>">Partner Perks</a>
            <?php if($isSystemAdmin):?><a class="cv-button cv-button-primary" href="/admin/agent.php?new=1">Open Agent Chat</a><?php endif;?>
        </div>
    </section>

    <section class="cv-partner-stat-grid" aria-label="Relationship summary">
        <div><strong><?= (int)$rel['completed_events'] ?></strong><span>Completed events</span></div>
        <div><strong><?= count($daily) ?></strong><span>Daily Events</span></div>
        <div><strong><?= (int)$rel['verified_visits'] ?></strong><span>Verified visits</span></div>
        <div><strong><?= (int)$rel['repeat_attendees'] ?></strong><span>Repeat attendees</span></div>
        <div><strong><?= count(array_filter($perks,static fn(array $p):bool=>(string)$p['status']==='active')) ?></strong><span>Active perks</span></div>
        <div><strong><?= (int)$rel['business_benefits_issued'] ?></strong><span>Benefits issued</span></div>
        <div><strong><?= (int)$rel['claims'] ?></strong><span>Claims</span></div>
        <div><strong><?= (int)$rel['return_claims'] ?></strong><span>Return claims</span></div>
    </section>

    <section class="cv-partner-grid">
        <article class="cv-card cv-partner-about">
            <span class="cv-eyebrow">ABOUT THE PARTNER</span>
            <h2><?= coveted_e((string)$rel['location_name']) ?></h2>
            <p><?= coveted_e(trim((string)($biz['description']??'')) !== '' ? (string)$biz['description'] : 'Add a concise business description in the Business Workspace.') ?></p>
            <dl>
                <?php if(trim((string)($biz['category_label']??''))!==''):?><div><dt>Category</dt><dd><?= coveted_e((string)$biz['category_label']) ?></dd></div><?php endif;?>
                <div><dt>Location</dt><dd><?= coveted_e(trim((string)$rel['location_name'].', '.(string)$rel['city'].', '.(string)$rel['region'],', ')) ?></dd></div>
                <?php if(trim((string)($biz['website_url']??''))!==''):?><div><dt>Website</dt><dd><a href="<?= coveted_e((string)$biz['website_url']) ?>" target="_blank" rel="noopener">Open website</a></dd></div><?php endif;?>
                <?php if(trim((string)($biz['phone']??''))!==''):?><div><dt>Business phone</dt><dd><?= coveted_e((string)$biz['phone']) ?></dd></div><?php endif;?>
            </dl>
        </article>

        <details class="cv-card cv-partner-editor">
            <summary><span><small>BUSINESS PROFILE</small><strong>Edit partner identity</strong></span><span>+</span></summary>
            <form method="post" class="cv-form">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="update_identity">
                <input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>">
                <label>Logo URL<input name="logo_url" maxlength="700" value="<?= coveted_e((string)($biz['logo_url']??'')) ?>"></label>
                <label>Cover URL<input name="cover_url" maxlength="700" value="<?= coveted_e((string)($biz['cover_url']??'')) ?>"></label>
                <label>Website URL<input name="website_url" maxlength="700" value="<?= coveted_e((string)($biz['website_url']??'')) ?>"></label>
                <label>Business phone<input name="phone" maxlength="80" value="<?= coveted_e((string)($biz['phone']??'')) ?>"></label>
                <label>Category<input name="category_label" maxlength="160" value="<?= coveted_e((string)($biz['category_label']??'')) ?>" placeholder="Restaurant, venue, studio…"></label>
                <button class="cv-button cv-button-primary" type="submit">Save Partner Identity</button>
            </form>
        </details>
    </section>

    <?php if($isSystemAdmin):?>
    <section class="cv-partner-grid cv-partner-crm-summary">
        <article class="cv-card">
            <span class="cv-eyebrow">RELATIONSHIP OWNERSHIP</span><h2>Who owns this partner?</h2>
            <p><?= trim((string)($crm['relationship_summary']??''))!=='' ? nl2br(coveted_e((string)$crm['relationship_summary'])) : 'Assign internal ownership and keep a concise working summary for the Agent and Coveted team.' ?></p>
        </article>
        <form class="cv-card cv-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="save_crm_state">
            <input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>">
            <label>Relationship owner<select name="relationship_owner_user_id"><option value="">Unassigned</option><?php foreach($adminUsers as $adminUser):?><option value="<?= (int)$adminUser['id'] ?>" <?= (int)($crm['relationship_owner_user_id']??0)===(int)$adminUser['id']?'selected':'' ?>><?= coveted_e((string)$adminUser['display_name']) ?></option><?php endforeach;?></select></label>
            <label>Internal relationship summary<textarea name="relationship_summary" maxlength="4000" rows="5"><?= coveted_e((string)($crm['relationship_summary']??'')) ?></textarea></label>
            <button class="cv-button cv-button-primary" type="submit">Save Ownership</button>
        </form>
    </section>

    <section id="contacts" class="cv-partner-section">
        <header><div><span class="cv-eyebrow">PARTNER CONTACTS</span><h2>The people behind the relationship</h2></div><span class="cv-status"><?= count($contacts) ?> contact<?= count($contacts)===1?'':'s' ?></span></header>
        <div class="cv-partner-grid">
            <div class="cv-stack">
                <?php if(!$contacts):?><article class="cv-card cv-empty"><h3>No partner contacts yet.</h3><p>Add the owner, GM, event lead or marketing contact so event and perk history has a human relationship attached.</p></article><?php endif;?>
                <?php foreach($contacts as $contact):?>
                    <article class="cv-card cv-partner-contact">
                        <div><span class="cv-kicker"><?= (int)$contact['is_primary']===1?'PRIMARY CONTACT':strtoupper((string)$contact['status']) ?></span><h3><?= coveted_e((string)$contact['full_name']) ?></h3><p><?= coveted_e(trim((string)($contact['role_title']??'')) ?: 'Partner contact') ?></p></div>
                        <dl><div><dt>Preferred</dt><dd><?= coveted_e(ucfirst(str_replace('_',' ',(string)$contact['preferred_contact']))) ?></dd></div><?php if(trim((string)($contact['email']??''))!==''):?><div><dt>Email</dt><dd><?= coveted_e((string)$contact['email']) ?></dd></div><?php endif;?><?php if(trim((string)($contact['phone']??''))!==''):?><div><dt>Phone</dt><dd><?= coveted_e((string)$contact['phone']) ?></dd></div><?php endif;?></dl>
                        <details><summary>Edit contact</summary><form method="post" class="cv-form cv-compact-form">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="save_contact"><input type="hidden" name="contact_ref" value="<?= coveted_e((string)$contact['public_id']) ?>">
                            <input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>">
                            <label>Name<input name="full_name" required maxlength="180" value="<?= coveted_e((string)$contact['full_name']) ?>"></label><label>Role<input name="role_title" maxlength="180" value="<?= coveted_e((string)($contact['role_title']??'')) ?>"></label>
                            <label>Email<input name="email" type="email" maxlength="255" value="<?= coveted_e((string)($contact['email']??'')) ?>"></label><label>Phone<input name="phone" maxlength="80" value="<?= coveted_e((string)($contact['phone']??'')) ?>"></label>
                            <label>Preferred<select name="preferred_contact"><?php foreach(['email','phone','text','in_person','other'] as $method):?><option value="<?= $method ?>" <?= (string)$contact['preferred_contact']===$method?'selected':'' ?>><?= coveted_e(ucfirst(str_replace('_',' ',$method))) ?></option><?php endforeach;?></select></label>
                            <label>Status<select name="status"><?php foreach(['active','inactive','archived'] as $s):?><option value="<?= $s ?>" <?= (string)$contact['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach;?></select></label>
                            <label class="cv-check-row"><input type="checkbox" name="is_primary" value="1" <?= (int)$contact['is_primary']===1?'checked':'' ?>> Primary contact</label><button class="cv-button" type="submit">Save Contact</button>
                        </form></details>
                    </article>
                <?php endforeach;?>
            </div>
            <form class="cv-card cv-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="save_contact">
                <input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>">
                <span class="cv-eyebrow">ADD CONTACT</span><h3>New partner contact</h3>
                <label>Name<input name="full_name" required maxlength="180"></label><label>Role / title<input name="role_title" maxlength="180"></label><label>Email<input name="email" type="email" maxlength="255"></label><label>Phone<input name="phone" maxlength="80"></label>
                <label>Preferred contact<select name="preferred_contact"><option value="email">Email</option><option value="phone">Phone</option><option value="text">Text</option><option value="in_person">In person</option><option value="other">Other</option></select></label>
                <label class="cv-check-row"><input type="checkbox" name="is_primary" value="1"> Primary contact</label><input type="hidden" name="status" value="active"><button class="cv-button cv-button-primary" type="submit">Add Contact</button>
            </form>
        </div>
    </section>

    <section id="followups" class="cv-partner-section">
        <header><div><span class="cv-eyebrow">FOLLOW-UPS</span><h2>Keep the human relationship moving</h2></div><div class="cv-tag-row"><span class="cv-pill"><?= count($openFollowups) ?> open</span><?php if($overdueFollowups):?><span class="cv-pill cv-pill-alert"><?= count($overdueFollowups) ?> overdue</span><?php endif;?></div></header>
        <div class="cv-partner-grid">
            <div class="cv-stack">
                <?php if(!$followups):?><article class="cv-card cv-empty"><h3>No follow-ups yet.</h3><p>Create a next action after a call, event, reward review or partner request.</p></article><?php endif;?>
                <?php foreach($followups as $followup):?><article class="cv-card cv-followup <?= (string)$followup['status']==='open' && strtotime((string)$followup['due_at'])<time()?'is-overdue':'' ?>">
                    <div><span class="cv-kicker"><?= strtoupper((string)$followup['priority']) ?> · <?= strtoupper((string)$followup['status']) ?></span><h3><?= coveted_e((string)$followup['title']) ?></h3><p><?= coveted_e((string)($followup['detail']??'')) ?></p><small>Due <?= coveted_e($format((string)$followup['due_at'])) ?> · <?= coveted_e((string)$followup['assignee_name']) ?><?php if(trim((string)($followup['contact_name']??''))!==''):?> · <?= coveted_e((string)$followup['contact_name']) ?><?php endif;?></small></div>
                    <?php if((string)$followup['status']==='open'):?><form method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="followup_status"><input type="hidden" name="followup_ref" value="<?= coveted_e((string)$followup['public_id']) ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>"><button class="cv-button cv-button-soft" type="submit">Complete</button></form><?php endif;?>
                </article><?php endforeach;?>
            </div>
            <form class="cv-card cv-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="add_followup"><input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>">
                <span class="cv-eyebrow">NEXT ACTION</span><h3>Create follow-up</h3><label>Title<input name="title" required maxlength="190"></label><label>Details<textarea name="detail" maxlength="4000" rows="4"></textarea></label>
                <label>Contact<select name="contact_ref"><option value="">Relationship-wide</option><?php foreach($contacts as $contact):?><option value="<?= coveted_e((string)$contact['public_id']) ?>"><?= coveted_e((string)$contact['full_name']) ?></option><?php endforeach;?></select></label>
                <label>Assigned to<select name="assigned_user_id"><option value="">Unassigned</option><?php foreach($adminUsers as $adminUser):?><option value="<?= (int)$adminUser['id'] ?>"><?= coveted_e((string)$adminUser['display_name']) ?></option><?php endforeach;?></select></label>
                <label>Due<input type="datetime-local" name="due_at" required></label><label>Priority<select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option></select></label><button class="cv-button cv-button-primary" type="submit">Create Follow-up</button>
            </form>
        </div>
    </section>

    <section class="cv-partner-section">
        <header><div><span class="cv-eyebrow">NOTES & CONVERSATIONS</span><h2>What the partner told us</h2></div></header>
        <div class="cv-partner-grid">
            <div class="cv-stack">
                <?php foreach(array_slice($interactions,0,12) as $interaction):?><article class="cv-card cv-activity-card"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_',' ',(string)$interaction['interaction_type']))) ?> · <?= coveted_e(strtoupper((string)$interaction['direction'])) ?></span><h3><?= coveted_e(trim((string)($interaction['subject']??'')) ?: 'Partner interaction') ?></h3><p><?= nl2br(coveted_e((string)$interaction['summary'])) ?></p><small><?= coveted_e($format((string)$interaction['occurred_at'])) ?><?php if(trim((string)($interaction['contact_name']??''))!==''):?> · <?= coveted_e((string)$interaction['contact_name']) ?><?php endif;?> · <?= coveted_e((string)$interaction['author_name']) ?></small></article><?php endforeach;?>
                <?php foreach(array_slice($notes,0,8) as $note):?><article class="cv-card cv-activity-card"><span class="cv-kicker"><?= coveted_e(strtoupper((string)$note['note_type'])) ?> NOTE</span><p><?= nl2br(coveted_e((string)$note['body'])) ?></p><small><?= coveted_e($format((string)$note['created_at'])) ?> · <?= coveted_e((string)$note['author_name']) ?><?php if(trim((string)($note['contact_name']??''))!==''):?> · <?= coveted_e((string)$note['contact_name']) ?><?php endif;?></small></article><?php endforeach;?>
            </div>
            <div class="cv-stack">
                <form class="cv-card cv-form" method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="add_interaction"><input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>"><span class="cv-eyebrow">LOG INTERACTION</span><h3>Call, email, meeting or conversation</h3><label>Contact<select name="contact_ref"><option value="">No specific contact</option><?php foreach($contacts as $contact):?><option value="<?= coveted_e((string)$contact['public_id']) ?>"><?= coveted_e((string)$contact['full_name']) ?></option><?php endforeach;?></select></label><div class="cv-inline-fields"><label>Type<select name="interaction_type"><option value="call">Call</option><option value="email">Email</option><option value="text">Text</option><option value="meeting">Meeting</option><option value="in_person">In person</option><option value="other">Other</option></select></label><label>Direction<select name="direction"><option value="outbound">Outbound</option><option value="inbound">Inbound</option><option value="internal">Internal</option></select></label></div><label>Subject<input name="subject" maxlength="190"></label><label>Summary<textarea name="summary" required maxlength="6000" rows="5"></textarea></label><label>Occurred at<input type="datetime-local" name="occurred_at"></label><button class="cv-button cv-button-primary" type="submit">Log Interaction</button></form>
                <form class="cv-card cv-form" method="post"><input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>"><input type="hidden" name="action" value="add_note"><input type="hidden" name="business" value="<?= coveted_e((string)$biz['public_id']) ?>"><input type="hidden" name="group" value="<?= coveted_e((string)$rel['group_public_id']) ?>"><input type="hidden" name="location" value="<?= coveted_e((string)$rel['location_public_id']) ?>"><span class="cv-eyebrow">ADD NOTE</span><label>Note type<select name="note_type"><option value="relationship">Relationship</option><option value="timeline">Timeline</option><option value="contact">Contact-specific</option></select></label><label>Contact<select name="contact_ref"><option value="">No specific contact</option><?php foreach($contacts as $contact):?><option value="<?= coveted_e((string)$contact['public_id']) ?>"><?= coveted_e((string)$contact['full_name']) ?></option><?php endforeach;?></select></label><label>Note<textarea name="body" required maxlength="6000" rows="5"></textarea></label><button class="cv-button" type="submit">Add Note</button></form>
            </div>
        </div>
    </section>
    <?php endif;?>

    <section id="events" class="cv-partner-section">
        <header><div><span class="cv-eyebrow">EVENTS</span><h2>Gatherings that built the relationship</h2></div><span class="cv-status"><?= count($events) ?> event<?= count($events)===1?'':'s' ?></span></header>
        <div class="cv-card cv-table-card"><div class="cv-table-wrap"><table class="cv-table"><thead><tr><th>Event</th><th>Status</th><th>Verified</th><th>Benefits</th><th>Claims</th></tr></thead><tbody><?php foreach($events as $event):?><tr><td><strong><?= coveted_e((string)$event['title']) ?></strong><br><small><?= coveted_e($format((string)$event['starts_at'])) ?></small></td><td><?= coveted_e(ucfirst((string)$event['status'])) ?></td><td><?= (int)$event['verified_attendance'] ?></td><td><?= (int)$event['business_benefits_issued'] ?></td><td><?= (int)$event['claims'] ?></td></tr><?php endforeach;?></tbody></table></div></div>
    </section>

    <section id="perks" class="cv-partner-section"><header><div><span class="cv-eyebrow">PARTNER PERKS</span><h2>Standing relationship value</h2></div><a class="cv-button cv-button-soft" href="/partner-perks.php?<?= coveted_e($profileQuery) ?>">Manage Perks</a></header><div class="cv-partner-card-grid"><?php if(!$perks):?><article class="cv-card cv-empty"><h3>No Partner Perks yet.</h3><p>Create an ongoing discount, preferred access offer, surprise reward or return-visit perk.</p></article><?php endif;?><?php foreach($perks as $perk):?><article class="cv-card"><span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_',' ',(string)$perk['perk_type']))) ?></span><h3><?= coveted_e((string)$perk['title']) ?></h3><p><?= coveted_e((string)$perk['reward_title']) ?> · <?= coveted_e(ucfirst((string)$perk['distribution_mode'])) ?></p><div class="cv-meta-row"><span><?= (int)$perk['issued_count'] ?> issued</span><span><?= (int)$perk['claimed_count'] ?> claimed</span><span><?= coveted_e(ucfirst((string)$perk['status'])) ?></span></div></article><?php endforeach;?></div></section>

    <section id="opportunities" class="cv-partner-section"><header><div><span class="cv-eyebrow">OPPORTUNITIES</span><h2>What Coveted thinks deserves attention</h2></div><?php if($isSystemAdmin):?><a class="cv-button cv-button-soft" href="/admin/agent.php?new=1">Discuss with Agent</a><?php endif;?></header><div class="cv-partner-card-grid"><?php if(!$opportunities):?><article class="cv-card cv-empty"><h3>No current partner opportunity is being flagged.</h3><p>The Agent and relationship engine will surface a recommendation when the underlying data justifies one.</p></article><?php endif;?><?php foreach(array_slice($opportunities,0,12) as $item):?><article class="cv-card cv-opportunity-card"><span class="cv-kicker">P<?= (int)($item['priority']??3) ?> · <?= coveted_e(strtoupper(str_replace('_',' ',(string)($item['kind']??'partner')))) ?></span><h3><?= coveted_e((string)$item['title']) ?></h3><p><?= coveted_e((string)$item['detail']) ?></p><small><?= coveted_e((string)($item['evidence']??'')) ?></small><?php if(trim((string)($item['href']??''))!==''):?><a class="cv-text-link" href="<?= coveted_e((string)$item['href']) ?>">Open action →</a><?php endif;?></article><?php endforeach;?></div></section>

    <section id="timeline" class="cv-partner-section"><header><div><span class="cv-eyebrow">RELATIONSHIP TIMELINE</span><h2>One history of the partner relationship</h2></div><span class="cv-status"><?= count($timeline) ?> activity records</span></header><div class="cv-card cv-partner-timeline"><?php if(!$timeline):?><div class="cv-empty"><h3>No timeline activity yet.</h3></div><?php endif;?><?php foreach($timeline as $item):?><article><div class="cv-timeline-marker" aria-hidden="true"></div><div><span class="cv-kicker"><?= coveted_e(strtoupper((string)$item['category'])) ?></span><h3><?= coveted_e((string)$item['title']) ?></h3><p><?= coveted_e((string)$item['detail']) ?></p><small><?= coveted_e($format((string)$item['at'])) ?></small></div></article><?php endforeach;?></div></section>
</div>

<?php
if ($isSystemAdmin) coveted_admin_ui_end();
coveted_page_end();
?>
