<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/event_guest_mix.php';

$admin=coveted_require_system_admin();
$pdo=coveted_db();
if(coveted_system_sample_mode($admin,$pdo)) coveted_redirect('/admin/system-preview.php?view=events');

$eventRef=trim((string)($_GET['event']??''));
if($eventRef===''){
    $first=$pdo->query("SELECT public_id FROM events WHERE status IN ('draft','published') AND starts_at>UTC_TIMESTAMP() ORDER BY starts_at,id LIMIT 1")->fetchColumn();
    $eventRef=(string)($first?:'');
}
$snapshot=$eventRef!==''?coveted_event_guest_mix_snapshot($admin,$eventRef,$pdo):null;
$segmentLabel=static fn(string $v):string=>match($v){'reconnect'=>'Reconnect','widen_circle'=>'Widen circle','reliable_repeat'=>'Reliable repeat','small_format'=>'Small-format fit','pace'=>'Pace / hold',default=>'Balanced'};

coveted_page_start('Guest Mix','',true);
coveted_admin_ui_start($admin,'events','Guest Mix');
?>
<div class="cv-admin-page-head">
 <div><span class="cv-eyebrow">GUEST MIX / INVITATION INTELLIGENCE</span><h1>Build the right room without over-inviting.</h1><p>Event-specific recommendations from active membership, verified attendance, reconnection needs, format history and invitation pacing. This is not a member ranking.</p></div>
 <?php if($snapshot): ?><div class="cv-action-row"><a class="cv-button cv-button-soft" href="/admin/member-relationships.php?group=<?=coveted_e(rawurlencode((string)$snapshot['event']['group_ref']))?>">Relationship Intelligence</a><a class="cv-button cv-button-primary" href="/admin/event.php?event=<?=coveted_e(rawurlencode((string)$snapshot['event']['public_id']))?>#invitations">Open Canonical Invitations</a></div><?php endif; ?>
</div>

<?php if(!$snapshot): ?>
<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-empty"><strong>No future draft or published event is available.</strong><span>Create or schedule an event before building a guest mix.</span></div></section>
<?php else: $e=(array)$snapshot['event'];$c=(array)$snapshot['counts'];$segments=(array)$snapshot['segments']; ?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
 <div><span>Capacity</span><strong><?= (int)$e['capacity']>0?(int)$e['capacity']:'Open'?></strong><small><?=coveted_e((string)$e['group_name'])?></small></div>
 <div><span>Attending</span><strong><?= (int)$c['attending']?></strong><small><?= (int)$c['open_seats']?> open seats</small></div>
 <div><span>Recommended</span><strong><?= (int)$c['recommended_count']?></strong><small>From <?= (int)$c['candidate_pool']?> eligible candidates</small></div>
 <div><span>Paced / hold</span><strong><?= (int)$c['paced_members']?></strong><small>Recent invitation load is high</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">EVENT</span><h2><?=coveted_e((string)$e['title'])?></h2></div><span class="cv-status"><?=coveted_e(ucfirst((string)$e['status']))?></span></div>
 <dl class="cv-admin-event-definition-list">
  <div><dt>Group</dt><dd><?=coveted_e((string)$e['group_name'])?></dd></div>
  <div><dt>Starts</dt><dd><?=coveted_e((string)$e['starts_at'])?> UTC</dd></div>
  <div><dt>Format</dt><dd><?=coveted_e(ucwords(str_replace('_',' ',(string)$e['event_type'])))?></dd></div>
  <div><dt>Existing invitations</dt><dd><?= (int)$c['invited']?></dd></div>
  <div><dt>Waitlist</dt><dd><?= (int)$c['waitlist']?></dd></div>
  <div><dt>Hosts excluded</dt><dd><?= (int)$c['hosts']?></dd></div>
 </dl>
 <div class="cv-alert"><strong>System Admin decides.</strong> Guest Mix is read-only. It never sends invitations. Use the canonical Event invitation workspace after reviewing these recommendations.</div>
</section>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">RECOMMENDED MIX</span><h2>Best fit for this event</h2></div><span class="cv-pill">Event-specific fit</span></div>
 <div class="cv-admin-list">
 <?php foreach((array)$snapshot['recommended'] as $member): ?>
  <div class="cv-admin-list-row">
   <span class="cv-admin-list-copy"><strong><?=coveted_e((string)$member['display_name'])?></strong><small><?=$segmentLabel((string)$member['segment'])?> · <?=coveted_e(implode(' · ',array_slice((array)$member['reasons'],0,3)))?></small></span>
   <span><strong><?= (int)$member['fit_score']?></strong><br><small>fit / 100</small></span>
  </div>
 <?php endforeach; if(!$snapshot['recommended']): ?><div class="cv-admin-empty"><strong>No invitation recommendation is needed right now.</strong><span>Capacity may already be covered, or eligible members are currently paced.</span></div><?php endif; ?>
 </div>
</section>

<section class="cv-admin-panel">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">MIX TARGETS</span><h2>Why the room changes</h2></div></div>
 <dl class="cv-admin-event-definition-list">
  <div><dt>Reconnect</dt><dd><?= (int)($segments['reconnect']??0)?></dd></div>
  <div><dt>Widen circle</dt><dd><?= (int)($segments['widen_circle']??0)?></dd></div>
  <div><dt>Reliable repeat</dt><dd><?= (int)($segments['reliable_repeat']??0)?></dd></div>
  <div><dt>Small-format evidence</dt><dd><?= (int)($segments['small_format']??0)?></dd></div>
  <div><dt>Pace / hold</dt><dd><?= (int)($segments['pace']??0)?></dd></div>
 </dl>
 <p>Reconnect candidates have prior verified attendance but no verified event in 90 days. “Widen circle” means active members with limited verified participation. Small-format fit is based only on prior verified attendance at events with capacity 18 or below.</p>
</section>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
 <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">FULL ELIGIBLE POOL</span><h2>Recommendation evidence</h2></div></div>
 <div class="cv-admin-list">
 <?php foreach((array)$snapshot['candidates'] as $member): ?>
  <div class="cv-admin-list-row">
   <span class="cv-admin-list-copy"><strong><?=coveted_e((string)$member['display_name'])?></strong><small><?=$segmentLabel((string)$member['segment'])?> · <?= (int)$member['verified_events']?> verified group events · <?= (int)$member['invitations_60d']?> invitations in the ±60-day event window</small><small><?=coveted_e(implode(' · ',(array)$member['reasons']))?></small></span>
   <span><strong><?= (int)$member['fit_score']?></strong><br><small><?=!empty($member['paced_out'])?'hold':'eligible'?></small></span>
  </div>
 <?php endforeach; ?>
 </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap"><div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PRIVACY + AUTHORITY</span><h2>Invitation fit, not member worth</h2></div></div><p><?=coveted_e((string)$snapshot['privacy'])?> <?=coveted_e((string)$snapshot['authority'])?></p></section>
<?php endif; ?>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
