<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/group_relationship_planning.php';
require_once dirname(__DIR__) . '/app/event_proposals.php';

$admin=coveted_require_system_admin();
$pdo=coveted_db();
if(coveted_system_sample_mode($admin,$pdo))coveted_redirect('/admin/system-preview.php?view=people');
$error='';
$groupRef=trim((string)($_GET['group']??$_POST['group_ref']??''));
$groups=coveted_member_relationship_groups($admin,$pdo);
if($groupRef==='' && $groups)$groupRef=(string)$groups[0]['public_id'];
$plan=null;
try{if($groupRef!=='')$plan=coveted_group_relationship_plan($admin,$groupRef,$pdo);}catch(Throwable $e){error_log('Group Relationship Planning workspace unavailable: '.$e->getMessage());$error='Unable to build that group relationship plan right now.';}
$playbooks=coveted_event_proposal_schema_available($pdo)?coveted_event_playbooks($admin,false,$pdo):[];
$recommendedPlaybook=null;
if($plan){
    $key=(string)$plan['event']['playbook_key'];
    foreach($playbooks as $pb)if((string)$pb['playbook_key']===$key){$recommendedPlaybook=$pb;break;}
    $recommendedPlaybook ??= $playbooks[0]??null;
}
$opportunity=null;
if($plan && (string)$plan['event']['opportunity_key']!==''){
    try{$opportunity=coveted_event_opportunity_by_key($admin,(string)$plan['event']['opportunity_key'],$pdo);}catch(Throwable){}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    coveted_require_csrf();
    try{
        if((string)($_POST['action']??'')!=='create_proposal')throw new InvalidArgumentException('Unsupported Group Relationship Planning action.');
        $groupRef=trim((string)($_POST['group_ref']??''));
        $plan=coveted_group_relationship_plan($admin,$groupRef,$pdo);
        if(empty($plan['event']['recommended']))throw new InvalidArgumentException('This group does not currently have a recommendation for another Event.');
        $opportunityKey=(string)$plan['event']['opportunity_key'];
        if($opportunityKey==='' || !coveted_event_opportunity_by_key($admin,$opportunityKey,$pdo))throw new InvalidArgumentException('The recommended venue is not currently proposal-ready. Review Event Opportunities or the partner relationship first.');
        $playbookRef=trim((string)($_POST['playbook_ref']??''));
        $result=coveted_event_proposal_create_from_opportunity($admin,$opportunityKey,$playbookRef,$pdo);
        $proposalRef=(string)$result['public_id'];
        if(!empty($result['created'])){
            $targetTotal=array_sum(array_map('intval',(array)$plan['target_mix']));
            $capacity=max(1,(int)$plan['event']['capacity']);
            $expected=max(1,min($capacity,$targetTotal>0?$targetTotal:$capacity));
            coveted_event_proposal_update($admin,$proposalRef,[
                'playbook_ref'=>$playbookRef,
                'title'=>(string)$plan['event']['title'],
                'concept'=>(string)$plan['event']['concept'],
                'proposed_start_at'=>(string)$plan['event']['suggested_start_at'],
                'expected_attendance'=>$expected,
                'capacity'=>$capacity,
                'internal_notes'=>'Created from Group Relationship Planning. Objective: '.(string)$plan['objective']['label'].'. Evidence: '.(string)$plan['evidence'],
            ],$pdo);
            coveted_audit('group.relationship_plan_proposal_created','event_proposal',$proposalRef,[
                'group_ref'=>(string)$plan['group']['public_id'],'objective'=>(string)$plan['objective']['key'],'opportunity_key'=>$opportunityKey,
                'capacity'=>$capacity,'target_mix'=>(array)$plan['target_mix'],'location_ref'=>(string)($plan['location']['location_ref']??''),
            ],(int)$admin['id']);
        }
        coveted_redirect('/admin/event-proposals.php?proposal='.rawurlencode($proposalRef).'&created=1');
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Group Relationship Planning proposal failed: '.$e->getMessage());$error='Unable to create that Event Proposal right now.';}
}

$label=static fn(string $value):string=>ucwords(str_replace('_',' ',$value));
$fmt=static function(?string $value):string{
    $value=trim((string)$value);if($value==='')return 'Not scheduled';
    try{return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');}catch(Throwable){return $value;}
};
$segmentLabel=static fn(string $value):string=>match($value){'first_event'=>'First event','reliable_recent'=>'Reliable recent','reconnect'=>'Reconnect','paced'=>'Paced / hold','balanced'=>'Balanced',default=>ucwords(str_replace('_',' ',$value))};

coveted_page_start('Group Relationship Planning','',true);
coveted_admin_ui_start($admin,'member-relationships','Group Relationship Planning');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">RELATIONSHIPS · NEXT EVENT</span>
        <h1>Plan the next Event around the relationship network.</h1>
        <p>Combine Group Health, Member Journey pacing, verified Guest Mix evidence, completed Event Learning and partner/location history before proposing another gathering.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/member-relationships.php<?= $groupRef!==''?'?group='.coveted_e(rawurlencode($groupRef)):'' ?>">Relationship Intelligence</a>
        <a class="cv-button cv-button-soft" href="/admin/member-actions.php">Member Actions</a>
        <a class="cv-button cv-button-soft" href="/admin/event-proposals.php">Event Proposals</a>
    </div>
</div>
<?php if($error!==''):?><div class="cv-alert cv-alert-error"><?=coveted_e($error)?></div><?php endif;?>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">GROUPS</span><h2>Relationship planning queue</h2></div><span class="cv-status">System Admin</span></div>
    <div class="cv-admin-list">
        <?php foreach($groups as $group):?>
        <a class="cv-admin-list-row" href="/admin/group-relationship-planning.php?group=<?=coveted_e(rawurlencode((string)$group['public_id']))?>">
            <span class="cv-admin-list-copy"><strong><?=coveted_e((string)$group['name'])?></strong><small><?= (int)$group['active_members'] ?> active members · <?= (int)$group['events_90d'] ?> completed Events / 90d</small></span>
            <span class="cv-status"><?=coveted_e((string)($group['city']?:'Group'))?></span>
        </a>
        <?php endforeach;?>
        <?php if(!$groups):?><div class="cv-admin-empty"><strong>No active groups.</strong><span>Create and activate a group before relationship planning.</span></div><?php endif;?>
    </div>
</section>

<?php if($plan):$objective=(array)$plan['objective'];$event=(array)$plan['event'];$metrics=(array)$plan['metrics'];?>
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">NEXT-BEST EVENT</span><h2><?=coveted_e((string)$plan['group']['name'])?></h2></div><span class="cv-status"><?=coveted_e($label((string)$plan['health']))?></span></div>
    <h3><?=coveted_e((string)$objective['label'])?></h3>
    <p><?=coveted_e((string)$objective['detail'])?></p>
    <div class="cv-alert"><strong>Verified evidence:</strong> <?=coveted_e((string)$plan['evidence'])?></div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Social format</dt><dd><?=coveted_e((string)$event['social_format'])?></dd></div>
        <div><dt>Event type</dt><dd><?=coveted_e($label((string)$event['event_type']))?></dd></div>
        <div><dt>Capacity</dt><dd><?= (int)$event['capacity'] ?></dd></div>
        <div><dt>Suggested timing</dt><dd><?=coveted_e($fmt((string)$event['suggested_start_at']))?></dd></div>
        <div><dt>Participation breadth</dt><dd><?=number_format((float)$metrics['participation_breadth'],1)?>%</dd></div>
        <div><dt>Invitation pacing</dt><dd><?= (int)$plan['paced_members'] ?> paced · <?= (int)$plan['lifecycle_holds'] ?> lifecycle holds</dd></div>
    </dl>
    <?php if((int)$plan['future_events']>0):?><div class="cv-alert"><strong>Existing Event cadence.</strong> <?= (int)$plan['future_events'] ?> future Event<?= (int)$plan['future_events']===1?' is':'s are' ?> already scheduled, so Group Planning will not recommend another proposal.</div><?php endif;?>
    <?php if(!empty($event['recommended']) && $opportunity && $recommendedPlaybook):?>
    <form method="post" class="cv-stack" data-confirm="Create a System Admin Event Proposal from this relationship plan? No Event or invitation will be created yet.">
        <input type="hidden" name="csrf_token" value="<?=coveted_e(coveted_csrf_token())?>">
        <input type="hidden" name="action" value="create_proposal">
        <input type="hidden" name="group_ref" value="<?=coveted_e((string)$plan['group']['public_id'])?>">
        <label>Event Playbook
            <select name="playbook_ref" required>
                <?php foreach($playbooks as $pb):?><option value="<?=coveted_e((string)$pb['public_id'])?>" <?= (int)$pb['id']===(int)$recommendedPlaybook['id']?'selected':'' ?>><?=coveted_e((string)$pb['name'])?> · <?=coveted_e($label((string)$pb['event_type']))?></option><?php endforeach;?>
            </select>
        </label>
        <button class="cv-button cv-button-primary" type="submit">Create Event Proposal</button>
        <small>Proposal only. A System Admin must still review, approve and explicitly convert it before a canonical draft Event exists.</small>
    </form>
    <?php elseif(!empty($event['recommended']) && !$opportunity):?>
    <div class="cv-alert"><strong>Partner readiness needed.</strong> The relationship plan is valid, but the selected venue is not currently an active Event Opportunity. Review the partner/location relationship before creating a proposal.</div>
    <?php endif;?>
</section>
<?php endif;?>
</div>

<?php if($plan):?>
<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">TARGET GUEST MIX</span><h2>Who the Event is for</h2></div><span class="cv-status">Private Admin evidence</span></div>
    <div class="cv-admin-metric-grid">
        <?php foreach((array)$plan['target_mix'] as $segment=>$count):?><div><span><?=coveted_e($segmentLabel((string)$segment))?></span><strong><?= (int)$count ?></strong><small>target seats</small></div><?php endforeach;?>
    </div>
    <p class="cv-form-help">Targets are relationship-planning counts, not member scores. Paced and lifecycle-hold members are excluded from the target mix.</p>
    <div class="cv-admin-list cv-admin-section-gap">
        <?php foreach(array_slice((array)$plan['invite_candidates'],0,40) as $candidate):?>
        <div class="cv-admin-list-row">
            <span class="cv-admin-list-copy"><strong><?=coveted_e((string)$candidate['display_name'])?></strong><small><?=coveted_e($segmentLabel((string)$candidate['segment']))?> · <?=coveted_e((string)$candidate['reason'])?></small></span>
            <span class="cv-status"><?= (int)$candidate['verified_90d'] ?> / 90d</span>
        </div>
        <?php endforeach;?>
    </div>
</section>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PARTNER / LOCATION FIT</span><h2><?=coveted_e((string)($plan['location']['business_name']??'No proven venue yet'))?></h2></div><span class="cv-status"><?=coveted_e((string)($plan['learning']['confidence']['label']??'low'))?> learning confidence</span></div>
    <?php if($plan['location']):?>
    <h3><?=coveted_e((string)$plan['location']['location_name'])?></h3>
    <p><?=coveted_e($label((string)$plan['location']['relationship_status']))?> relationship · <?= (int)$plan['location']['events'] ?> learned Event<?= (int)$plan['location']['events']===1?'':'s' ?> · <?=number_format((float)$plan['location']['avg_result'],1)?> average Event Learning result.</p>
    <?php endif;?>
    <div class="cv-admin-list cv-admin-section-gap">
        <?php foreach((array)$plan['location_candidates'] as $location):?>
        <div class="cv-admin-list-row">
            <span class="cv-admin-list-copy"><strong><?=coveted_e((string)$location['business_name'])?> · <?=coveted_e((string)$location['location_name'])?></strong><small><?=coveted_e($label((string)$location['relationship_status']))?> · <?= (int)$location['events'] ?> completed · <?= (int)$location['verified'] ?> verified visits · <?=number_format((float)$location['attendance_rate'],1)?>% attendance realization</small></span>
            <span class="cv-status"><?=coveted_e((string)$location['confidence'])?></span>
        </div>
        <?php endforeach;?>
        <?php if(!$plan['location_candidates']):?><div class="cv-admin-empty"><strong>No learned partner/location history yet.</strong><span>Group Planning will not invent a venue fit without canonical Event evidence.</span></div><?php endif;?>
    </div>
</section>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORITY + PRIVACY</span><h2>Planning is not execution.</h2></div></div>
    <p><?=coveted_e((string)$plan['authority'])?></p>
    <p><?=coveted_e((string)$plan['privacy'])?></p>
</section>
<?php endif;?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>
