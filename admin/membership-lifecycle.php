<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/membership_lifecycle.php';

$admin=coveted_require_system_admin();
$pdo=coveted_db();
if(coveted_system_sample_mode($admin,$pdo))coveted_redirect('/admin/system-preview.php?view=people');

$error='';
$notice=trim((string)($_SESSION['membership_lifecycle_notice']??''));
unset($_SESSION['membership_lifecycle_notice']);
$schemaReady=coveted_membership_lifecycle_schema_available($pdo);
$memberRef=trim((string)($_GET['member']??$_POST['member_ref']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    coveted_require_csrf();
    try{
        if(!$schemaReady)throw new RuntimeException('Import the Membership Lifecycle CRM migration before changing lifecycle state.');
        if((string)($_POST['action']??'')!=='lifecycle_save')throw new InvalidArgumentException('Unsupported lifecycle action.');
        $memberRef=trim((string)($_POST['member_ref']??''));
        $state=strtolower(trim((string)($_POST['state']??'')));
        $reason=trim((string)($_POST['reason']??''));
        $renewal=trim((string)($_POST['renewal_due_at']??''));
        $paused=trim((string)($_POST['paused_until']??''));
        $result=coveted_membership_lifecycle_set_state(
            $admin,$memberRef,$state,$reason,
            ['surface'=>'membership_lifecycle_admin'],
            'system_admin',
            $renewal!==''?$renewal:null,
            $paused!==''?$paused:null,
            $pdo
        );
        $_SESSION['membership_lifecycle_notice']=!empty($result['changed'])
            ? 'Membership lifecycle moved from '.ucfirst((string)$result['from_state']).' to '.ucfirst((string)$result['state']).'.'
            : 'Membership lifecycle dates and Admin context were saved.';
        coveted_redirect('/admin/membership-lifecycle.php?member='.rawurlencode((string)$result['member_ref']));
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){
        error_log('Membership Lifecycle CRM action failed: '.$e->getMessage());
        $error=$e instanceof RuntimeException?$e->getMessage():'Unable to save that membership lifecycle change.';
    }
}

$index=[];$selectedUser=null;$current=null;$history=[];$recommendation=null;
if($schemaReady){
    try{
        $index=coveted_membership_lifecycle_admin_index($admin,160,$pdo);
        if($memberRef===''&&$index)$memberRef=(string)$index[0]['member_ref'];
        if($memberRef!==''){
            $selectedUser=coveted_membership_lifecycle_user($pdo,$memberRef,false);
            $current=coveted_membership_lifecycle_current($selectedUser,$pdo);
            $history=coveted_membership_lifecycle_history($admin,$memberRef,40,$pdo);
            if((string)$selectedUser['status']==='active'){
                try{
                    $metrics=coveted_member_journey_metrics_row($pdo,(string)$selectedUser['public_id']);
                    $recommendation=coveted_membership_lifecycle_recommendation($metrics,$current);
                }catch(Throwable){}
            }
        }
    }catch(Throwable $e){
        error_log('Membership Lifecycle CRM workspace unavailable: '.$e->getMessage());
        if($error==='')$error='Membership Lifecycle CRM is temporarily unavailable.';
    }
}

$states=coveted_membership_lifecycle_states();
$counts=array_fill_keys($states,0);
foreach($index as $row){$state=(string)$row['state'];$counts[$state]=($counts[$state]??0)+1;}
$stateLabel=static fn(string $state):string=>ucwords(str_replace('_',' ',$state));
$fmt=static function(?string $value):string{
    $value=trim((string)$value);if($value==='')return 'Not set';
    try{return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y g:i A');}catch(Throwable){return $value;}
};

coveted_page_start('Membership Lifecycle CRM','',true);
coveted_admin_ui_start($admin,'membership-lifecycle','Membership Lifecycle CRM');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PEOPLE · MEMBERSHIP CRM</span>
        <h1>Membership lifecycle, with evidence and history.</h1>
        <p>Track invited → applicant → active → engaged → drifting → paused → alumni as private CRM state. Group membership and account access remain governed by their canonical controls.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/member-journeys.php<?= $memberRef!==''?'?member='.coveted_e(rawurlencode($memberRef)):'' ?>">Member Journey</a>
        <a class="cv-button cv-button-soft" href="/admin/member-relationships.php">Relationship Intelligence</a>
        <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
    </div>
</div>

<?php if(!$schemaReady):?>
<div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import <code>database/migrations/20260907_membership_lifecycle_crm.sql</code>, then reload this workspace.</div>
<?php endif;?>
<?php if($error!==''):?><div class="cv-alert cv-alert-error"><?=coveted_e($error)?></div><?php endif;?>
<?php if($notice!==''):?><div class="cv-alert"><?=coveted_e($notice)?></div><?php endif;?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Invited / applicant</span><strong><?= (int)$counts['invited']+(int)$counts['applicant'] ?></strong><small>pre-active lifecycle</small></div>
    <div><span>Active / engaged</span><strong><?= (int)$counts['active']+(int)$counts['engaged'] ?></strong><small>current membership</small></div>
    <div><span>Drifting</span><strong><?= (int)$counts['drifting'] ?></strong><small>evidence-based review</small></div>
    <div><span>Paused / alumni</span><strong><?= (int)$counts['paused']+(int)$counts['alumni'] ?></strong><small>relationship-planning hold</small></div>
</div>

<?php if($schemaReady):?>
<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LIFECYCLE QUEUE</span><h2>Members</h2></div><span class="cv-status">System Admin only</span></div>
    <div class="cv-admin-list">
        <?php if(!$index):?><div class="cv-admin-empty"><strong>No lifecycle candidates yet.</strong><span>Invited accounts, active non-guest group members, and persisted lifecycle records appear here.</span></div><?php endif;?>
        <?php foreach($index as $row):$rec=is_array($row['recommendation']??null)?(array)$row['recommendation']:null;?>
        <a class="cv-admin-list-row" href="/admin/membership-lifecycle.php?member=<?=coveted_e(rawurlencode((string)$row['member_ref']))?>">
            <span class="cv-admin-list-copy">
                <strong><?=coveted_e((string)$row['display_name'])?></strong>
                <small><?=coveted_e($stateLabel((string)$row['state']))?> · <?= (int)$row['active_groups'] ?> active group<?= (int)$row['active_groups']===1?'':'s' ?> · <?= (int)$row['verified_90d'] ?> verified events / 90d</small>
                <?php if($rec):?><small><?=coveted_e((string)$rec['title'])?> · <?=coveted_e((string)$rec['evidence'])?></small><?php endif;?>
            </span>
            <span class="cv-status"><?=coveted_e($stateLabel((string)$row['state']))?></span>
        </a>
        <?php endforeach;?>
    </div>
</section>

<?php if($selectedUser&&$current):
    $allowed=array_values(array_unique(array_merge([(string)$current['state']],coveted_membership_lifecycle_allowed_targets((string)$current['state']))));
?>
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MEMBER LIFECYCLE</span><h2><?=coveted_e((string)$selectedUser['display_name'])?></h2></div><span class="cv-status"><?=coveted_e($stateLabel((string)$current['state']))?></span></div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>State since</dt><dd><?=coveted_e($fmt((string)$current['state_since']))?></dd></div>
        <div><dt>Renewal due</dt><dd><?=coveted_e($fmt((string)$current['renewal_due_at']))?></dd></div>
        <div><dt>Paused until</dt><dd><?=coveted_e($fmt((string)$current['paused_until']))?></dd></div>
        <div><dt>Account status</dt><dd><?=coveted_e($stateLabel((string)$selectedUser['status']))?></dd></div>
    </dl>
    <?php if($recommendation):?>
    <div class="cv-alert"><strong><?=coveted_e((string)$recommendation['title'])?></strong><br><?=coveted_e((string)$recommendation['detail'])?><br><small><?=coveted_e((string)$recommendation['evidence'])?></small></div>
    <?php endif;?>
    <form method="post" class="cv-stack" onsubmit="return confirm('Save this private membership lifecycle change? Account and group access are not changed by this CRM action.');">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>">
        <input type="hidden" name="action" value="lifecycle_save">
        <input type="hidden" name="member_ref" value="<?=coveted_e((string)$selectedUser['public_id'])?>">
        <label>Lifecycle state
            <select name="state" required>
                <?php foreach($allowed as $state):?><option value="<?=coveted_e($state)?>" <?=$state===(string)$current['state']?'selected':''?>><?=coveted_e($stateLabel($state))?></option><?php endforeach;?>
            </select>
        </label>
        <label>Renewal due <input type="datetime-local" name="renewal_due_at" value="<?=coveted_e((string)($current['renewal_due_at']!==''?str_replace(' ','T',substr((string)$current['renewal_due_at'],0,16)):''))?>"></label>
        <label>Paused until <input type="datetime-local" name="paused_until" value="<?=coveted_e((string)($current['paused_until']!==''?str_replace(' ','T',substr((string)$current['paused_until'],0,16)):''))?>"></label>
        <label>Admin reason <textarea name="reason" rows="3" maxlength="1000" placeholder="Why is this lifecycle state or timing appropriate?"><?=coveted_e((string)$current['reason'])?></textarea></label>
        <button class="cv-button cv-button-primary" type="submit">Save lifecycle</button>
    </form>
    <div class="cv-alert cv-admin-section-gap"><strong>Authority boundary.</strong> This CRM state does not suspend an account, remove a group membership, send a message, or change an RSVP. Those remain separate canonical actions.</div>
</section>
<?php endif;?>
</div>

<?php if($selectedUser&&$current):?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">LIFECYCLE HISTORY</span><h2>Append-only transition record</h2></div></div>
    <div class="cv-admin-list">
        <?php if(!$history):?><div class="cv-admin-empty"><strong>No persisted transition history yet.</strong><span>The current state is still derived from the canonical account baseline until the first lifecycle save.</span></div><?php endif;?>
        <?php foreach($history as $row):?>
        <div class="cv-admin-list-row">
            <span class="cv-admin-list-copy"><strong><?=coveted_e($stateLabel((string)$row['from_state']).' → '.$stateLabel((string)$row['to_state']))?></strong><small><?=coveted_e((string)($row['reason']?:'No private Admin reason recorded.'))?></small><small><?=coveted_e((string)$row['source'])?> · <?=coveted_e((string)$row['actor'])?></small></span>
            <small><?=coveted_e($fmt((string)$row['created_at']))?></small>
        </div>
        <?php endforeach;?>
    </div>
</section>
<?php endif;?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MODEL</span><h2>Lifecycle is CRM context, not a member score.</h2></div></div>
    <p>Transitions use explicit state rules plus canonical Member Journey evidence. The system does not persist a numerical engagement score, infer personality, rank members publicly, or convert a lifecycle state into access control.</p>
</section>
<?php endif;?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>
