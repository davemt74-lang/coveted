<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/member_journey.php';
require_once dirname(__DIR__) . '/app/member_journey_scan.php';
require_once dirname(__DIR__) . '/app/membership_lifecycle.php';

$admin=coveted_require_system_admin();
$pdo=coveted_db();
if(coveted_system_sample_mode($admin,$pdo)){
    coveted_redirect('/admin/system-preview.php?view=people');
}

$memberRef=trim((string)($_GET['member']??''));
$error='';$snapshot=null;$lifecycle=null;$lifecycleHistory=[];
try{
    $index=coveted_member_journey_admin_index_fast($admin,100,$pdo);
    if($memberRef==='' && $index)$memberRef=(string)$index[0]['member_ref'];
    if($memberRef!==''){
        $snapshot=coveted_member_journey_snapshot($admin,$memberRef,$pdo);
        if(coveted_membership_lifecycle_schema_available($pdo)){
            $lifecycleUser=coveted_membership_lifecycle_user($pdo,$memberRef,false);
            $lifecycle=coveted_membership_lifecycle_current($lifecycleUser,$pdo);
            $lifecycle['recommendation']=coveted_membership_lifecycle_recommendation((array)$snapshot['metrics'],$lifecycle);
            $lifecycleHistory=coveted_membership_lifecycle_history($admin,$memberRef,6,$pdo);
        }
    }
}catch(Throwable $e){
    error_log('Member Journey workspace unavailable: '.$e->getMessage());
    $index=$index??[];
    $error=$e instanceof InvalidArgumentException?$e->getMessage():'Member Journey Intelligence is temporarily unavailable.';
}

$stateLabel=static fn(string $state):string=>match($state){
    'over_contacted'=>'Reduce pressure','recovery'=>'Recovery','drifting'=>'Drifting','unactivated'=>'Not activated',
    'post_event'=>'Post-event','momentum'=>'Momentum','value_engaged'=>'Value engaged','decline_pressure'=>'Decline pressure',
    default=>'Steady',
};
$lifecycleLabel=static fn(string $state):string=>ucwords(str_replace('_',' ',$state));
$kindLabel=static fn(string $kind):string=>match($kind){
    'invitation'=>'Invitation','rsvp'=>'RSVP','attendance'=>'Attendance','reward'=>'Reward',default=>ucfirst($kind),
};
$fmtOrigin=static function(?string $value):string{
    $value=trim((string)$value);if($value==='')return 'Not recorded';
    try{return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y g:i A');}catch(Throwable){return $value;}
};

coveted_page_start('Member Journeys','',true);
coveted_admin_ui_start($admin,'member-journeys','Member Journeys');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">MEMBER JOURNEY INTELLIGENCE</span>
        <h1>Next-best relationship actions from verified Coveted history.</h1>
        <p>Review invitations, RSVPs, verified attendance, reward engagement and communication pressure without creating a public score or automatic outreach.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/membership-lifecycle.php<?= $memberRef!==''?'?member='.coveted_e(rawurlencode($memberRef)):'' ?>">Membership Lifecycle</a>
        <a class="cv-button cv-button-soft" href="/admin/guest-conversions.php">Guest Conversion</a>
        <a class="cv-button cv-button-soft" href="/admin/member-relationships.php">Relationship Intelligence</a>
        <a class="cv-button cv-button-soft" href="/admin/agent-tasks.php">Agent Tasks</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=users">Users</a>
    </div>
</div>

<?php if($error!==''):?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif;?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <?php
    $priorityOne=count(array_filter($index,static fn(array $r):bool=>(int)$r['priority']===1));
    $priorityTwo=count(array_filter($index,static fn(array $r):bool=>(int)$r['priority']===2));
    $paced=count(array_filter($index,static fn(array $r):bool=>(string)$r['action']==='pause_invitations'));
    $reconnect=count(array_filter($index,static fn(array $r):bool=>in_array((string)$r['action'],['reconnect','reconnect_small_format','recover_after_no_show'],true)));
    ?>
    <div><span>P1 journeys</span><strong><?= $priorityOne ?></strong><small>needs attention</small></div>
    <div><span>P2 journeys</span><strong><?= $priorityTwo ?></strong><small>next-best actions</small></div>
    <div><span>Paced members</span><strong><?= $paced ?></strong><small>reduce invitation pressure</small></div>
    <div><span>Reconnect / recovery</span><strong><?= $reconnect ?></strong><small>relationship opportunities</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">JOURNEY QUEUE</span><h2>Member relationship attention</h2></div><span class="cv-status">System Admin only</span></div>
    <div class="cv-admin-list">
        <?php if(!$index):?>
            <div class="cv-admin-empty"><strong>No active member journeys yet.</strong><span>Active non-guest group members will appear here as Coveted accumulates canonical interaction history.</span></div>
        <?php else: foreach($index as $row):?>
            <a class="cv-admin-list-row" href="/admin/member-journeys.php?member=<?= coveted_e(rawurlencode((string)$row['member_ref'])) ?>">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e((string)$row['display_name']) ?></strong>
                    <small><?= coveted_e((string)$row['title']) ?> · <?= coveted_e((string)$row['evidence']) ?></small>
                </span>
                <span class="cv-status"><?= coveted_e($stateLabel((string)$row['state'])) ?></span>
            </a>
        <?php endforeach; endif;?>
    </div>
</section>

<?php if($snapshot):
    $member=(array)$snapshot['member'];$metrics=(array)$snapshot['metrics'];$decision=(array)$snapshot['decision'];$prefs=(array)$snapshot['preferences'];
    $origin=is_array($snapshot['membership_origin']??null)?(array)$snapshot['membership_origin']:null;
?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">NEXT BEST ACTION</span><h2><?= coveted_e((string)$member['display_name']) ?></h2></div>
        <span class="cv-status"><?= coveted_e($stateLabel((string)$decision['state'])) ?></span>
    </div>
    <h3><?= coveted_e((string)$decision['title']) ?></h3>
    <p><?= coveted_e((string)$decision['detail']) ?></p>
    <div class="cv-alert"><strong>Evidence:</strong> <?= coveted_e((string)$decision['evidence']) ?></div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/?view=events">Review Events</a>
        <a class="cv-button cv-button-soft" href="/admin/?view=benefits">Review Rewards</a>
        <a class="cv-button cv-button-soft" href="/admin/member-relationships.php">Relationship Intelligence</a>
    </div>
</section>

<?php if($origin):$originInviter=(array)($origin['invited_by']??[]);$originReferrer=is_array($origin['referrer']??null)?(array)$origin['referrer']:null;?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">GUEST → MEMBER ORIGIN</span><h2>Accepted Invite to Stay</h2></div>
        <span class="cv-status">Converted</span>
    </div>
    <p>This member entered through the canonical Guest → Invite to Stay → Member path. The outcome is historical provenance only; it does not create a score or alter current access.</p>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Converted group</dt><dd><?=coveted_e((string)$origin['group_name'])?></dd></div>
        <div><dt>Accepted</dt><dd><?=coveted_e($fmtOrigin((string)$origin['converted_at']))?></dd></div>
        <div><dt>Invite approved by</dt><dd><?=coveted_e((string)($originInviter['display_name']??'Unknown'))?></dd></div>
        <div><dt>Known referral path</dt><dd><?= $originReferrer?coveted_e((string)$originReferrer['display_name']):'Not established from Guest Pass history' ?></dd></div>
    </dl>
    <div class="cv-alert"><strong>Canonical outcome:</strong> The member conversion was recorded only after the guest accepted <code><?=coveted_e((string)$origin['invitation_ref'])?></code>.</div>
    <div class="cv-action-row"><a class="cv-button cv-button-soft" href="/admin/guest-conversions.php">Review Guest Conversion</a></div>
</section>
<?php endif;?>

<?php if($lifecycle):$lifecycleRec=is_array($lifecycle['recommendation']??null)?(array)$lifecycle['recommendation']:null;?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">MEMBERSHIP LIFECYCLE</span><h2>Lifecycle outcome</h2></div>
        <span class="cv-status"><?=coveted_e($lifecycleLabel((string)$lifecycle['state']))?></span>
    </div>
    <p>Member Journey evidence feeds the private lifecycle recommendation layer. Lifecycle state remains separate from account and group access.</p>
    <?php if($lifecycleRec):?><div class="cv-alert"><strong><?=coveted_e((string)$lifecycleRec['title'])?></strong><br><?=coveted_e((string)$lifecycleRec['detail'])?><br><small><?=coveted_e((string)$lifecycleRec['evidence'])?></small></div><?php endif;?>
    <div class="cv-action-row"><a class="cv-button cv-button-primary" href="/admin/membership-lifecycle.php?member=<?=coveted_e(rawurlencode((string)$member['public_id']))?>">Review Lifecycle CRM</a></div>
    <?php if($lifecycleHistory):?>
    <div class="cv-admin-list cv-admin-section-gap">
        <?php foreach(array_slice($lifecycleHistory,0,3) as $row):?>
        <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?=coveted_e($lifecycleLabel((string)$row['from_state']).' → '.$lifecycleLabel((string)$row['to_state']))?></strong><small><?=coveted_e((string)($row['reason']?:'No private Admin reason recorded.'))?></small></span><small><?=coveted_e((string)$row['created_at'])?></small></div>
        <?php endforeach;?>
    </div>
    <?php endif;?>
</section>
<?php else:?>
<div class="cv-alert cv-admin-section-gap"><strong>Membership Lifecycle CRM is not installed.</strong> Import <code>database/migrations/20260907_membership_lifecycle_crm.sql</code> to add private lifecycle state and history to Member Journey.</div>
<?php endif;?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Verified attendance</span><strong><?= (int)$metrics['verified_365d'] ?></strong><small><?= (int)$metrics['verified_90d'] ?> in 90d · <?= (int)$metrics['verified_30d'] ?> in 30d</small></div>
    <div><span>Invitation pressure</span><strong><?= (int)$metrics['invitations_30d'] ?></strong><small>30d · <?= (int)$metrics['future_invitations'] ?> future</small></div>
    <div><span>Reward engagement</span><strong><?= (int)$metrics['rewards_claimed_180d'] ?></strong><small><?= (int)$metrics['rewards_issued_180d'] ?> issued in 180d</small></div>
    <div><span>Event messages</span><strong><?= (int)$metrics['event_messages_30d'] ?></strong><small>30d communication pressure</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PARTICIPATION PATTERNS</span><h2>Evidence, not personality inference</h2></div></div>
    <dl class="cv-admin-event-definition-list">
        <div><dt>Last verified attendance</dt><dd><?= coveted_e((string)($metrics['last_verified_at']?:'None')) ?></dd></div>
        <div><dt>Small-format visits</dt><dd><?= (int)$metrics['small_format_365d'] ?> in 365d</dd></div>
        <div><dt>No-shows</dt><dd><?= (int)$metrics['no_shows_180d'] ?> in 180d</dd></div>
        <div><dt>Declines</dt><dd><?= (int)$metrics['declines_180d'] ?> in 180d</dd></div>
        <div><dt>Average RSVP response</dt><dd><?= $prefs['average_response_hours']!==null?coveted_e((string)$prefs['average_response_hours']).'h':'Not enough history' ?></dd></div>
    </dl>
    <?php if(!empty($prefs['formats'])):?>
        <div class="cv-admin-list">
            <?php foreach((array)$prefs['formats'] as $format):?>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e(ucwords(str_replace('_',' ',(string)$format['event_type']))) ?></strong><small>Verified completed-event format history</small></span><span class="cv-status"><?= (int)$format['visits'] ?> visits</span></div>
            <?php endforeach;?>
        </div>
    <?php endif;?>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">GROUP RELATIONSHIPS</span><h2>Current memberships</h2></div></div>
    <div class="cv-admin-list">
        <?php foreach((array)$snapshot['groups'] as $group):?>
            <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$group['name']) ?></strong><small><?= coveted_e(ucwords(str_replace('_',' ',(string)$group['group_role']))) ?> · <?= coveted_e((string)$group['membership_status']) ?></small></span></div>
        <?php endforeach;?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">JOURNEY TIMELINE</span><h2>Canonical interactions</h2></div></div>
    <div class="cv-admin-list">
        <?php foreach((array)$snapshot['timeline'] as $row):?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e($kindLabel((string)$row['kind']).' · '.(string)$row['title']) ?></strong>
                    <small><?= coveted_e((string)$row['occurred_at']) ?> · <?= coveted_e(ucwords(str_replace('_',' ',(string)$row['detail']))) ?></small>
                </span>
                <?php if((string)$row['event_ref']!==''):?><a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$row['event_ref'])) ?>">Event</a><?php endif;?>
            </div>
        <?php endforeach;?>
    </div>
</section>

<div class="cv-alert cv-admin-section-gap"><strong>Agent boundary.</strong> <?= coveted_e((string)$snapshot['authority']) ?> <?= coveted_e((string)$snapshot['privacy']) ?></div>
<?php endif;?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>