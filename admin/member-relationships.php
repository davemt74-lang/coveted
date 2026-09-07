<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/member_relationships.php';

$admin=coveted_require_system_admin();
$pdo=coveted_db();
if(coveted_system_sample_mode($admin,$pdo)) coveted_redirect('/admin/system-preview.php?view=groups');

$groups=coveted_member_relationship_groups($admin,$pdo);
$selectedRef=trim((string)($_GET['group']??''));
if($selectedRef==='' && $groups) $selectedRef=(string)$groups[0]['public_id'];
$snapshot=$selectedRef!==''?coveted_member_relationship_group_snapshot($admin,$selectedRef,$pdo):null;

$fmtDate=static function(?string $value):string{
    if(!$value) return 'No verified activity';
    $ts=strtotime($value); return $ts?date('M j, Y',$ts):$value;
};
$healthLabel=static fn(string $value):string=>ucwords(str_replace('_',' ',$value));

$totalDrift=0;$groupsAttention=0;$totalReconnect=0;
foreach($groups as $row){
    try{$s=coveted_member_relationship_group_snapshot($admin,(string)$row['public_id'],$pdo);}catch(Throwable){continue;}
    $m=(array)$s['metrics'];$totalDrift+=(int)$m['drifting_members'];$totalReconnect+=(int)$m['reconnect_pairs'];
    if(in_array((string)$s['health'],['fading','fragmented','under_engaged','over_scheduled'],true)||(int)$m['drifting_members']>0)$groupsAttention++;
}

coveted_page_start('Member Relationship Intelligence','',true);
coveted_admin_ui_start($admin,'member-relationships','Member Relationship Intelligence');
?>
<div class="cv-admin-page-head">
  <div><span class="cv-eyebrow">RELATIONSHIP INTELLIGENCE</span><h1>Strengthen the network between events.</h1><p>Private, Admin-side signals derived from active membership and verified Coveted attendance—not followers, popularity scores or private Mutual Reconnect choices.</p></div>
  <div class="cv-action-row"><a class="cv-button cv-button-soft" href="/admin/event-learning.php">Event Learning</a><a class="cv-button cv-button-primary" href="/admin/event-opportunities.php">Event Opportunities</a></div>
</div>

<div class="cv-admin-metric-grid cv-admin-section-gap">
  <div><span>Active groups</span><strong><?=count($groups)?></strong><small>Relationship health coverage</small></div>
  <div><span>Need attention</span><strong><?=$groupsAttention?></strong><small>Drift, fragmentation or fatigue</small></div>
  <div><span>Drifting members</span><strong><?=$totalDrift?></strong><small>Prior verified attendance, none in 90 days</small></div>
  <div><span>Reconnect pairs</span><strong><?=$totalReconnect?></strong><small>Recurring co-attendance, no overlap in 90 days</small></div>
</div>

<?php if(!$snapshot): ?>
<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No active group relationship history yet.</strong><span>Verified event attendance will create relationship evidence automatically.</span></div></section>
<?php else: $m=(array)$snapshot['metrics']; ?>
<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">GROUP HEALTH</span><h2>Community portfolio</h2></div></div>
 <div class="cv-admin-list">
 <?php foreach($groups as $row):
   try{$gSnap=coveted_member_relationship_group_snapshot($admin,(string)$row['public_id'],$pdo);}catch(Throwable){continue;}
   $gm=(array)$gSnap['metrics']; ?>
  <a class="cv-admin-list-row" href="/admin/member-relationships.php?group=<?=coveted_e(rawurlencode((string)$row['public_id']))?>">
   <span class="cv-admin-list-copy"><strong><?=coveted_e((string)$row['name'])?></strong><small><?=$healthLabel((string)$gSnap['health'])?> · <?=number_format((float)$gm['participation_breadth'],1)?>% recent breadth</small><small><?= (int)$gm['drifting_members']?> drifting · <?= (int)$gm['reconnect_pairs']?> reconnect pairs</small></span>
   <span class="cv-status"><?=$healthLabel((string)$gSnap['health'])?></span>
  </a>
 <?php endforeach; ?>
 </div>
</section>

<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">SELECTED GROUP</span><h2><?=coveted_e((string)$snapshot['group']['name'])?></h2></div><span class="cv-status"><?=$healthLabel((string)$snapshot['health'])?></span></div>
 <dl class="cv-admin-event-definition-list">
  <div><dt>Active members</dt><dd><?= (int)$m['active_members']?></dd></div>
  <div><dt>Participation breadth</dt><dd><?=number_format((float)$m['participation_breadth'],1)?>%</dd></div>
  <div><dt>Attendance concentration</dt><dd><?=number_format((float)$m['attendance_concentration'],1)?>%</dd></div>
  <div><dt>Events / 90d</dt><dd><?= (int)$m['events_90d']?> vs <?= (int)$m['events_prior_90d']?> prior</dd></div>
  <div><dt>Drifting</dt><dd><?= (int)$m['drifting_members']?></dd></div>
  <div><dt>Under-engaged</dt><dd><?= (int)$m['under_engaged_members']?></dd></div>
  <div><dt>Recurring connections</dt><dd><?= (int)$m['recurring_connections']?></dd></div>
  <div><dt>Reconnect pairs</dt><dd><?= (int)$m['reconnect_pairs']?></dd></div>
 </dl>
 <?php foreach((array)$snapshot['recommendations'] as $rec): ?>
  <div class="cv-alert"><strong><?=coveted_e((string)$rec['title'])?></strong><br><?=coveted_e((string)$rec['detail'])?><br><small><?=coveted_e((string)$rec['evidence'])?></small></div>
 <?php endforeach; ?>
</section>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MEMBER DRIFT</span><h2>Who has fallen out of cadence?</h2></div></div>
 <div class="cv-admin-list">
 <?php foreach((array)$snapshot['drifting'] as $member): ?>
  <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?=coveted_e((string)$member['display_name'])?></strong><small><?=coveted_e(ucwords(str_replace('_',' ',(string)$member['group_role'])))?> · <?= (int)$member['verified_events']?> verified events</small></span><small>Last verified<br><?=$fmtDate($member['last_verified_at'])?></small></div>
 <?php endforeach; if(!$snapshot['drifting']): ?><div class="cv-admin-empty"><strong>No drifting prior attendees.</strong><span>Everyone with verified history has participated within 90 days.</span></div><?php endif; ?>
 </div>
</section>

<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RECONNECTION</span><h2>Recurring connections to renew</h2></div></div>
 <div class="cv-admin-list">
 <?php foreach((array)$snapshot['reconnect_pairs'] as $pair): ?>
  <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?=coveted_e((string)$pair['member_a']['display_name'])?> + <?=coveted_e((string)$pair['member_b']['display_name'])?></strong><small><?= (int)$pair['coattendance']?> verified Coveted events together</small></span><small>Last overlap<br><?=$fmtDate((string)$pair['last_coattended_at'])?></small></div>
 <?php endforeach; if(!$snapshot['reconnect_pairs']): ?><div class="cv-admin-empty"><strong>No recurring reconnection pair is currently stale.</strong><span>This only measures verified Coveted co-attendance; it does not claim whether people know or meet elsewhere.</span></div><?php endif; ?>
 </div>
</section>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PARTICIPATION BREADTH</span><h2>No verified Coveted overlap</h2></div></div>
 <p>Active member pairs with no shared verified attendance in the last year. This is event evidence only—not a claim that they have never met.</p>
 <div class="cv-admin-list">
 <?php foreach(array_slice((array)$snapshot['no_verified_overlap'],0,12) as $pair): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?=coveted_e((string)$pair['member_a']['display_name'])?> + <?=coveted_e((string)$pair['member_b']['display_name'])?></strong><small>No verified Coveted event overlap in the measured window.</small></span></div><?php endforeach; ?>
 </div>
</section>
<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">FORMAT EVIDENCE</span><h2>Smaller-format history</h2></div></div>
 <p>This is behavioral evidence only: members listed here have verified attendance at multiple events with capacity 18 or below. It is not a personality label.</p>
 <div class="cv-admin-list">
 <?php foreach((array)$snapshot['small_format_members'] as $member): ?><div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?=coveted_e((string)$member['display_name'])?></strong><small><?= (int)$member['small_format_events']?> verified small-format events</small></span></div><?php endforeach; ?>
 </div>
</section>
</div>

<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PRIVACY BOUNDARY</span><h2>Relationship intelligence, not social scoring</h2></div></div><p><?=coveted_e((string)$snapshot['privacy'])?> Member and pair identities are visible only in this System Admin workspace; the broad Admin Agent context receives aggregate group-level signals.</p></section>
<?php endif; ?>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
