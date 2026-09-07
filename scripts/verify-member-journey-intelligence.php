<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Member Journey contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Member Journey contract failed: {$label}\n");exit(1);}
};
$before=static function(string $content,string $first,string $second,string $label):void{
    $a=strpos($content,$first);$b=strpos($content,$second);
    if($a===false||$b===false||$a>=$b){fwrite(STDERR,"Member Journey contract failed: {$label}\n");exit(1);}
};

$journey=$read('app/member_journey.php');
$scan=$read('app/member_journey_scan.php');
$relationships=$read('app/member_relationships.php');
$page=$read('admin/member-journeys.php');
$adminUi=$read('app/admin_ui.php');
$operations=$read('app/operations.php');
$brain=$read('app/admin_agent_brain.php');
$tasks=$read('app/admin_agent_tasks.php');
$loader=$read('assets/js/coveted.js');

$contains($journey,'function coveted_member_journey_snapshot','single-member journey snapshot is required');
$contains($journey,'function coveted_member_journey_decision','deterministic next-best-action engine is required');
$contains($journey,"'pause_invitations'",'invitation pacing action is required');
$contains($journey,"'recover_after_no_show'",'no-show recovery action is required');
$contains($journey,"'reconnect_small_format'",'small-format reconnection action is required');
$contains($journey,"'first_event_fit'",'first-event activation action is required');
$contains($journey,"'post_event_followup'",'post-event follow-up action is required');
$contains($journey,"'invite_again_soon'",'positive momentum action is required');
$contains($journey,"'partner_value'",'partner-value action is required');
$contains($journey,"ea.status IN ('checked_in','attended','left_early')",'verified attendance must drive participation evidence');
$contains($journey,'function coveted_member_journey_invitation_pressure','detail metrics need canonical invitation-cycle pressure');
$contains($journey,"ae.event_type='event.user_invited'",'re-issued invitation audit events must be authoritative');
$contains($journey,"JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json,'$.invitation_id'))=ei.public_id",'invitation audit cycles must be joined by canonical invitation ref');
$contains($journey,"n.notification_type LIKE 'event.%'",'event communication pressure must be measured');
$contains($journey,"ri.status='claimed'",'reward engagement must use canonical reward issuance state');
$contains($journey,"'decline_pressure'",'decline pressure state is required');
$before($journey,'} elseif ($declines>=3) {','} elseif ($verified365>0 && $verified90===0) {','three declines must pace before drift/reconnect logic');
$before($journey,'} elseif ($declines>=3) {','} elseif ($verified30>=2) {','three declines must pace before positive-momentum invitations');
$contains($journey,'Repeated recent declines are a clear pacing signal','decline pacing rationale must be explicit');
$contains($journey,"COALESCE((SELECT MAX(ae.created_at)",'RSVP response timing must use the latest canonical invitation cycle');
$contains($journey,"'privacy'=>'Member Journey Intelligence is System Admin-only",'member detail privacy boundary must be explicit');
$contains($journey,"'authority'=>'The Agent may recommend and track a next-best action",'member detail authority boundary must be explicit');

$contains($scan,'function coveted_member_journey_scan_rows','bounded aggregate member scan is required');
$contains($scan,'function coveted_member_journey_admin_index_fast','Admin queue must have aggregate-scan adapter');
$contains($scan,'function coveted_member_journey_agent_context_fast','Agent must use aggregate-scan context');
$contains($scan,"ae.event_type='event.user_invited'",'aggregate scan must count canonical re-invite audit cycles');
$contains($scan,"SUM(cycles.contact_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY))",'aggregate invitation pressure must count actual contact cycles in 30d');
$contains($scan,"'key'=>'member-journey-pacing'",'Agent must receive aggregate pacing awareness');
$contains($scan,"'key'=>'member-journey-reconnect'",'Agent must receive aggregate reconnect/recovery awareness');
$contains($scan,"'key'=>'member-journey-followup'",'Agent must receive aggregate post-event/momentum awareness');
$contains($scan,"'key'=>'member-journey-activation'",'Agent must receive aggregate activation awareness');
$contains($scan,'One bounded aggregate member scan per Agent context build','Agent performance boundary must be explicit');
$contains($scan,'Broad Agent context contains no member names, member refs','Agent privacy boundary must explicitly exclude member identity');
$contains($scan,'The Agent may recommend and track work, but System Admin explicitly performs','System Admin action authority must be explicit');
$contains($scan,'coveted_system_sample_mode($admin,$pdo)','live journey Agent context must be isolated in Sample Mode');
$missing($scan,"'?member='",'broad Agent context must not route by exact member identity');
$missing($scan,"'member-journey-'.(string)\$metrics['public_id']",'broad Agent context must not create per-member task keys');

foreach(['INSERT INTO','UPDATE event_invitations','UPDATE event_rsvps','UPDATE reward_issuances','coveted_notification_create(','coveted_event_invite_user(','CREATE TABLE','ALTER TABLE'] as $needle){
    $missing($journey,$needle,'journey detail service must remain read-only: '.$needle);
    $missing($scan,$needle,'journey aggregate service must remain read-only: '.$needle);
}

$contains($relationships,"require_once __DIR__ . '/member_journey_scan.php';",'Member Relationship service must load efficient journey scan');
$contains($relationships,'coveted_member_journey_agent_context_fast($admin,60,$pdo)','journey intelligence must enter the existing relationship Agent bridge through the aggregate scan');
$contains($relationships,"'member_journey'=>",'relationship context must expose compact journey summary');
$contains($relationships,"foreach(array_slice((array)(\$memberJourney['recommendations']??[]),0,8) as \$rec)\$recommendations[]=\$rec;",'journey recommendations must enter the existing promoted recommendation stream');
$contains($relationships,'Member Journey recommendations use opaque member refs','relationship bridge must document journey privacy');
$missing($relationships,'coveted_member_journey_agent_context($admin','Operations bridge must not use a per-member metrics-query loop');

$contains($operations,'$relationshipRecommendations=array_slice','Operations must continue consuming the canonical relationship recommendation stream');
$contains($operations,'$resultRecommendations=array_merge(','Member Journey recommendations must remain in the Agent-promoted event-results stream');
$contains($brain,"foreach (['event_planning','host_command','event_results'",'Admin Agent brain must continue promoting the shared Event Results stream while permitting additive intelligence streams');
$contains($tasks,'function coveted_admin_agent_tasks_sync_opportunities','proactive Agent tasks must use the canonical task queue');

$contains($page,"require_once dirname(__DIR__) . '/app/member_journey_scan.php';",'System Admin journey workspace must load aggregate scan');
$contains($page,'coveted_member_journey_admin_index_fast($admin,100,$pdo)','System Admin queue must avoid a per-member metrics query loop');
$contains($page,"coveted_admin_ui_start(\$admin,'member-journeys','Member Journeys')",'Member Journeys must own its active nav state');
$contains($page,'MEMBER JOURNEY INTELLIGENCE','System Admin journey workspace is required');
$contains($page,'NEXT BEST ACTION','journey workspace must display the current next-best action');
$contains($page,'JOURNEY TIMELINE','journey workspace must show canonical interaction history');
$contains($page,'Evidence, not personality inference','workspace must state the evidence boundary');
$contains($page,"coveted_redirect('/admin/system-preview.php?view=people')",'Sample Mode must not expose live member identities');

$contains($adminUi,"'/admin/member-journeys.php'",'Admin shell must recognize Member Journeys in Sample Mode routing');
$contains($adminUi,"'/admin/membership-lifecycle.php' => 'people'",'grouped People sample routes must resolve to the People preview');
$contains($adminUi,"coveted_admin_nav_link(\$active, 'member-journeys', '/admin/member-journeys.php', 'Member Journeys')",'Member Journeys must be first-class People navigation');
$missing($loader,'member-journeys-nav-v1.js','Member Journeys navigation must have one canonical static source, not a duplicate JS injector');

fwrite(STDOUT,"Member Journey Intelligence contract verified.\n");