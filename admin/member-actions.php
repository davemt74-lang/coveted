<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/member_relationship_actions.php';

$admin=coveted_require_system_admin();
$pdo=coveted_db();
if(coveted_system_sample_mode($admin,$pdo))coveted_redirect('/admin/system-preview.php?view=people');

$error='';
$notice=trim((string)($_SESSION['member_action_notice']??''));
unset($_SESSION['member_action_notice']);
$schemaReady=coveted_member_relationship_actions_schema_available($pdo);
$status=trim((string)($_GET['status']??'active')) ?: 'active';
$memberRef=trim((string)($_GET['member']??$_POST['member_ref']??''));
$actionRef=trim((string)($_GET['action']??$_POST['action_ref']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    coveted_require_csrf();
    try{
        if(!$schemaReady)throw new RuntimeException('Import the Member Relationship Actions migration before using this workflow.');
        $command=(string)($_POST['command']??'');
        if($command==='refresh'){
            $result=coveted_member_relationship_actions_sync_recommendations($admin,180,$pdo);
            $_SESSION['member_action_notice']='Member Journey refresh: '.(int)$result['created'].' new · '.(int)$result['refreshed'].' refreshed · '.(int)$result['expired'].' expired · '.(int)$result['lifecycle_holds'].' lifecycle holds.';
            coveted_redirect('/admin/member-actions.php');
        }
        $actionRef=coveted_member_relationship_action_ref((string)($_POST['action_ref']??''));
        if($command==='review'){
            coveted_member_relationship_action_review(
                $admin,$actionRef,(string)($_POST['execution_type']??''),(string)($_POST['draft_message']??''),(string)($_POST['event_ref']??''),$pdo
            );
            $_SESSION['member_action_notice']='Member action reviewed. It still requires explicit approval before execution.';
        }elseif($command==='approve'){
            coveted_member_relationship_action_approve($admin,$actionRef,$pdo);
            $_SESSION['member_action_notice']='Member action approved. No outreach has been sent yet.';
        }elseif($command==='execute'){
            if((string)($_POST['confirm_execute']??'')!=='1')throw new InvalidArgumentException('Confirm the approved action before executing it.');
            $executed=coveted_member_relationship_action_execute($admin,$actionRef,$pdo);
            $_SESSION['member_action_notice']='Approved action executed through the canonical '.str_replace('_',' ',(string)$executed['canonical_result_type']).' path.';
        }elseif($command==='skip'){
            coveted_member_relationship_action_skip($admin,$actionRef,(string)($_POST['note']??''),$pdo);
            $_SESSION['member_action_notice']='Member action skipped. No outreach was sent.';
        }elseif($command==='outcome'){
            coveted_member_relationship_action_record_outcome($admin,$actionRef,(string)($_POST['outcome_status']??''),(string)($_POST['outcome_note']??''),$pdo);
            $_SESSION['member_action_notice']='Member action outcome recorded for the relationship learning history.';
        }else{
            throw new InvalidArgumentException('Unsupported Member Action command.');
        }
        coveted_redirect('/admin/member-actions.php?action='.rawurlencode($actionRef));
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){
        error_log('Member Relationship Action failed: '.$e->getMessage());
        $error=$e instanceof RuntimeException?$e->getMessage():'Unable to complete that Member Action request.';
    }
}

$counts=array_fill_keys(coveted_member_relationship_action_statuses(),0);
$actions=[];$selected=null;$history=[];$eventChoices=[];$agentContext=[];
if($schemaReady){
    try{
        $counts=coveted_member_relationship_action_counts($admin,$pdo);
        $actions=coveted_member_relationship_actions_list($admin,$status,$memberRef!==''?$memberRef:null,180,$pdo);
        if($actionRef===''&&$actions)$actionRef=(string)$actions[0]['public_id'];
        if($actionRef!==''){
            $selected=coveted_member_relationship_action_by_ref($admin,$actionRef,$pdo);
            if($selected){
                $history=coveted_member_relationship_action_history($admin,$actionRef,40,$pdo);
                $eventChoices=coveted_member_relationship_action_event_choices($admin,(int)$selected['member_user_id'],$pdo);
            }
        }
        $agentContext=coveted_member_relationship_action_agent_context($admin,$pdo);
    }catch(Throwable $e){
        error_log('Member Action Queue unavailable: '.$e->getMessage());
        if($error==='')$error='Member Action Queue is temporarily unavailable.';
    }
}

$statusLabel=static fn(string $value):string=>ucwords(str_replace('_',' ',$value));
$actionLabel=static fn(string $value):string=>match($value){
    'pause_invitations'=>'Pacing hold','recover_after_no_show'=>'No-show recovery','reconnect_small_format'=>'Small-format reconnect',
    'reconnect'=>'Reconnect','first_event_fit'=>'First-event activation','post_event_value_followup'=>'Post-event value follow-up',
    'post_event_followup'=>'Post-event follow-up','invite_again_soon'=>'Invite again','partner_value'=>'Partner value',
    default=>ucwords(str_replace('_',' ',$value)),
};
$fmt=static function(?string $value):string{
    $value=trim((string)$value);if($value==='')return 'Not recorded';
    try{return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y g:i A');}catch(Throwable){return $value;}
};
$eventLabel=static function(array $row)use($fmt):string{
    return (string)$row['title'].' · '.(string)$row['group_name'].' · '.$fmt((string)$row['starts_at']);
};

coveted_page_start('Member Action Queue','',true);
coveted_admin_ui_start($admin,'member-actions','Member Action Queue');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PEOPLE · RELATIONSHIP EXECUTION</span>
        <h1>Turn Member Journey intelligence into controlled action.</h1>
        <p>Review the evidence, edit the Agent draft, approve the exact action, then execute through Coveted's canonical notification or Event invitation service. Recommendations never contact a member automatically.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/member-journeys.php<?= $selected?'?member='.coveted_e(rawurlencode((string)$selected['member_ref'])):'' ?>">Member Journeys</a>
        <a class="cv-button cv-button-soft" href="/admin/membership-lifecycle.php<?= $selected?'?member='.coveted_e(rawurlencode((string)$selected['member_ref'])):'' ?>">Membership Lifecycle</a>
        <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
    </div>
</div>

<?php if(!$schemaReady):?>
<div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import <code>database/migrations/20260908_member_relationship_actions.sql</code>, then reload this workspace.</div>
<?php endif;?>
<?php if($error!==''):?><div class="cv-alert cv-alert-error"><?=coveted_e($error)?></div><?php endif;?>
<?php if($notice!==''):?><div class="cv-alert"><?=coveted_e($notice)?></div><?php endif;?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Recommended</span><strong><?= (int)$counts['recommended'] ?></strong><small>awaiting review</small></div>
    <div><span>Reviewed</span><strong><?= (int)$counts['reviewed'] ?></strong><small>awaiting approval</small></div>
    <div><span>Approved</span><strong><?= (int)$counts['approved'] ?></strong><small>authorized, not yet executed</small></div>
    <div><span>Executed / outcomes</span><strong><?= (int)$counts['executed']+(int)$counts['outcome'] ?></strong><small>canonical action history</small></div>
</div>

<?php if($schemaReady):?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">JOURNEY SYNC</span><h2>Refresh the action queue from live evidence</h2></div>
        <span class="cv-status"><?= (int)($agentContext['attention']??0) ?> open</span>
    </div>
    <p>A refresh creates new recommendations, updates untouched recommendations, and expires only untouched recommendations whose live Journey evidence changed. Reviewed and approved actions are never silently rewritten or expired.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>">
        <input type="hidden" name="command" value="refresh">
        <button class="cv-button cv-button-primary" type="submit">Refresh Member Actions</button>
    </form>
</section>

<div class="cv-action-row cv-admin-section-gap">
    <?php foreach(['active','recommended','reviewed','approved','executed','outcome','skipped','expired','all'] as $filter):?>
        <a class="cv-button <?=$status===$filter?'cv-button-primary':'cv-button-soft'?>" href="/admin/member-actions.php?status=<?=coveted_e(rawurlencode($filter))?>"><?=coveted_e($statusLabel($filter))?></a>
    <?php endforeach;?>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MEMBER ACTION QUEUE</span><h2><?=coveted_e($statusLabel($status))?></h2></div><span class="cv-status">System Admin only</span></div>
    <div class="cv-admin-list">
        <?php if(!$actions):?><div class="cv-admin-empty"><strong>No actions in this view.</strong><span>Refresh from Member Journey Intelligence to create current recommendations.</span></div><?php endif;?>
        <?php foreach($actions as $row):?>
        <a class="cv-admin-list-row" href="/admin/member-actions.php?status=<?=coveted_e(rawurlencode($status))?>&amp;action=<?=coveted_e(rawurlencode((string)$row['public_id']))?>">
            <span class="cv-admin-list-copy">
                <strong><?=coveted_e((string)$row['member_name'])?></strong>
                <small>P<?= (int)$row['priority'] ?> · <?=coveted_e($actionLabel((string)$row['action_type']))?> · <?=coveted_e((string)$row['title'])?></small>
                <small><?=coveted_e((string)$row['evidence'])?></small>
            </span>
            <span class="cv-status"><?=coveted_e($statusLabel((string)$row['status']))?></span>
        </a>
        <?php endforeach;?>
    </div>
</section>

<?php if($selected):?>
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">ACTION REVIEW</span><h2><?=coveted_e((string)$selected['member_name'])?></h2></div>
        <span class="cv-status"><?=coveted_e($statusLabel((string)$selected['status']))?></span>
    </div>
    <h3><?=coveted_e((string)$selected['title'])?></h3>
    <p><?=coveted_e((string)$selected['detail'])?></p>
    <div class="cv-alert"><strong>Verified evidence:</strong> <?=coveted_e((string)$selected['evidence'])?></div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Journey state</dt><dd><?=coveted_e($statusLabel((string)$selected['journey_state']))?></dd></div>
        <div><dt>Recommended action</dt><dd><?=coveted_e($actionLabel((string)$selected['action_type']))?></dd></div>
        <div><dt>Execution</dt><dd><?=coveted_e($statusLabel((string)$selected['execution_type']))?></dd></div>
        <div><dt>Created</dt><dd><?=coveted_e($fmt((string)$selected['created_at']))?></dd></div>
        <?php if(!empty($selected['event_title'])):?><div><dt>Target Event</dt><dd><?=coveted_e((string)$selected['event_title'])?> · <?=coveted_e($fmt((string)$selected['event_starts_at']))?></dd></div><?php endif;?>
        <?php if(!empty($selected['canonical_result_type'])):?><div><dt>Canonical result</dt><dd><?=coveted_e($statusLabel((string)$selected['canonical_result_type']))?><?=!empty($selected['canonical_result_ref'])?' · '.coveted_e((string)$selected['canonical_result_ref']):''?></dd></div><?php endif;?>
    </dl>

    <?php if(in_array((string)$selected['status'],['recommended','reviewed'],true)):?>
    <form method="post" class="cv-stack">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>">
        <input type="hidden" name="command" value="review">
        <input type="hidden" name="action_ref" value="<?=coveted_e((string)$selected['public_id'])?>">
        <input type="hidden" name="member_ref" value="<?=coveted_e((string)$selected['member_ref'])?>">
        <label>Execution type
            <select name="execution_type" required>
                <?php foreach(coveted_member_relationship_action_execution_types() as $type):?>
                <option value="<?=coveted_e($type)?>" <?=$type===(string)$selected['execution_type']?'selected':''?> <?=((string)$selected['action_type']==='pause_invitations'&&$type!=='hold')?'disabled':''?>><?=coveted_e($statusLabel($type))?></option>
                <?php endforeach;?>
            </select>
        </label>
        <label>Event for an Event invitation
            <select name="event_ref">
                <option value="">Choose when execution type is Event invitation</option>
                <?php foreach($eventChoices as $event):?>
                <option value="<?=coveted_e((string)$event['public_id'])?>" <?=((int)($selected['target_event_id']??0)===(int)$event['id'])?'selected':''?>><?=coveted_e($eventLabel($event))?></option>
                <?php endforeach;?>
            </select>
        </label>
        <label>Agent draft / Admin-approved copy
            <textarea name="draft_message" rows="5" maxlength="2000" placeholder="No message is sent for a pacing hold."><?=coveted_e((string)($selected['draft_message']??''))?></textarea>
        </label>
        <button class="cv-button cv-button-primary" type="submit">Save review</button>
    </form>
    <?php endif;?>

    <?php if((string)$selected['status']==='reviewed'):?>
    <form method="post" class="cv-stack cv-admin-section-gap" onsubmit="return confirm('Approve this exact Member Action? Approval authorizes execution, but does not execute it yet.');">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>"><input type="hidden" name="command" value="approve"><input type="hidden" name="action_ref" value="<?=coveted_e((string)$selected['public_id'])?>">
        <button class="cv-button cv-button-primary" type="submit">Approve exact action</button>
    </form>
    <?php endif;?>

    <?php if((string)$selected['status']==='approved'):?>
    <div class="cv-alert cv-admin-section-gap"><strong>Approved, not executed.</strong> Coveted will re-check current member status, lifecycle holds, Journey pressure and Event eligibility immediately before the canonical action runs.</div>
    <?php if(trim((string)($selected['execution_error']??''))!==''):?><div class="cv-alert cv-alert-error"><strong>Last execution blocked:</strong> <?=coveted_e((string)$selected['execution_error'])?></div><?php endif;?>
    <form method="post" class="cv-stack" onsubmit="return confirm('Execute this approved Member Action now through the canonical Coveted service?');">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>"><input type="hidden" name="command" value="execute"><input type="hidden" name="action_ref" value="<?=coveted_e((string)$selected['public_id'])?>">
        <label><input type="checkbox" name="confirm_execute" value="1" required> I confirm this exact approved action should execute now.</label>
        <button class="cv-button cv-button-primary" type="submit">Execute approved action</button>
    </form>
    <?php endif;?>

    <?php if(in_array((string)$selected['status'],['recommended','reviewed','approved'],true)):?>
    <form method="post" class="cv-stack cv-admin-section-gap" onsubmit="return confirm('Skip this Member Action without outreach?');">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>"><input type="hidden" name="command" value="skip"><input type="hidden" name="action_ref" value="<?=coveted_e((string)$selected['public_id'])?>">
        <label>Skip note <input type="text" name="note" maxlength="1000" placeholder="Optional private Admin reason"></label>
        <button class="cv-button cv-button-soft" type="submit">Skip action</button>
    </form>
    <?php endif;?>

    <?php if((string)$selected['status']==='executed'):?>
    <form method="post" class="cv-stack cv-admin-section-gap">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>"><input type="hidden" name="command" value="outcome"><input type="hidden" name="action_ref" value="<?=coveted_e((string)$selected['public_id'])?>">
        <label>Outcome
            <select name="outcome_status" required><option value="positive">Positive</option><option value="neutral">Neutral</option><option value="negative">Negative</option><option value="unknown">Unknown / not enough evidence</option></select>
        </label>
        <label>Outcome note <textarea name="outcome_note" rows="3" maxlength="2000" placeholder="What happened after this action?"></textarea></label>
        <button class="cv-button cv-button-primary" type="submit">Record outcome</button>
    </form>
    <?php endif;?>

    <?php if((string)$selected['status']==='outcome'):?>
    <div class="cv-alert cv-admin-section-gap"><strong>Outcome: <?=coveted_e($statusLabel((string)$selected['outcome_status']))?></strong><br><?=coveted_e((string)($selected['outcome_note']?:'No private outcome note recorded.'))?></div>
    <?php endif;?>

    <div class="cv-action-row cv-admin-section-gap">
        <a class="cv-button cv-button-soft" href="/admin/member-journeys.php?member=<?=coveted_e(rawurlencode((string)$selected['member_ref']))?>">Open Member Journey</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=events">Review Events</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=benefits">Review Rewards</a>
    </div>
</section>
<?php endif;?>
</div>

<?php if($selected):?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ACTION HISTORY</span><h2>Recommendation → review → approval → execution → outcome</h2></div></div>
    <div class="cv-admin-list">
        <?php if(!$history):?><div class="cv-admin-empty"><strong>No action history yet.</strong><span>Each transition is written to the canonical audit stream.</span></div><?php endif;?>
        <?php foreach($history as $row):$eventName=str_replace('member.relationship_action.','',(string)$row['event_type']);$meta=(array)($row['metadata']??[]);?>
        <div class="cv-admin-list-row">
            <span class="cv-admin-list-copy"><strong><?=coveted_e($statusLabel($eventName))?></strong><small><?=coveted_e((string)$row['actor_name'])?><?=!empty($meta['reason'])?' · '.coveted_e((string)$meta['reason']):''?><?=!empty($meta['note'])?' · '.coveted_e((string)$meta['note']):''?></small></span>
            <small><?=coveted_e($fmt((string)$row['created_at']))?></small>
        </div>
        <?php endforeach;?>
    </div>
</section>
<?php endif;?>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY & LEARNING</span><h2>The Agent recommends. The System Admin authorizes.</h2></div></div>
    <p>Member identities, evidence and draft copy stay inside this private System Admin workspace. Broad Agent context receives aggregate queue and outcome counts only. Relationship messages are canonical <code>event.relationship_followup</code> notifications, so they feed existing communication-pressure evidence on the next Member Journey refresh. Event invitations use the existing guarded invitation service.</p>
    <div class="cv-alert"><strong>No autonomous outreach.</strong> A recommendation is not permission. Review and approval are separate durable states, and execution revalidates live eligibility before any contact.</div>
</section>
<?php endif;?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>
