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

$journey=$read('app/member_journey.php');
$scan=$read('app/member_journey_scan.php');
$relationships=$read('app/member_relationships.php');
$page=$read('admin/member-journeys.php');
$adminUi=$read('app/admin_ui.php');
$operations=$read('app/operations.php');
$brain=$read('app/admin_agent_brain.php');
$tasks=$read('app/admin_agent_tasks.php');

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
$contains($journey,"ei.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",'recent invitation pressure must be measured');
$contains($journey,"n.notification_type LIKE 'event.%'",'event communication pressure must be measured');
$contains($journey,"ri.status='claimed'",'reward engagement must use canonical reward issuance state');

$contains($scan,'function coveted_member_journey_scan_rows','bounded aggregate member scan is required');
$contains($scan,'function coveted_member_journey_admin_index_fast','Admin queue must have aggregate-scan adapter');
$contains($scan,'function coveted_member_journey_agent_context_fast','Agent must use aggregate-scan context');
$contains($scan,"'key'=>'member-journey-review'",'Agent must receive aggregate Member Journey queue awareness');
$contains($scan,"'key'=>'member-journey-'",'stable opaque per-member Agent opportunity key is required');
$contains($scan,"'title'=>'Review a member journey next-best action'",'broad per-member Agent title must not expose the member name');
$contains($scan,'if(count($individual)<6)','per-member proactive task fan-out must be capped');
$contains($scan,'One bounded aggregate member scan per Agent context build','Agent performance boundary must be explicit');
$contains($scan,'Broad Agent context contains no member names, email addresses','Agent privacy boundary must be explicit');
$contains($scan,'The Agent may recommend and track work, but System Admin explicitly performs','System Admin action authority must be explicit');
$contains($scan,'coveted_system_sample_mode($admin,$pdo)','live journey Agent context must be isolated in Sample Mode');

foreach(['INSERT INTO','UPDATE event_invitations','UPDATE event_rsvps','UPDATE reward_issuances','coveted_notification_create(','coveted_event_invite_user(','CREATE TABLE','ALTER TABLE'] as $needle){
    $missing($journey,$needle,'journey detail service must remain read-only: '.$needle);
    $missing($scan,$needle,'journey aggregate service must remain read-only: '.$needle);
}

$contains($relationships,"require_once __DIR__ . '/member_journey_scan.php';",'Member Relationship service must load efficient journey scan');
$contains($relationships,'coveted_member_journey_agent_context_fast($admin,60,$pdo)','journey intelligence must enter the existing relationship Agent bridge through the aggregate scan');
$contains($relationships,"'member_journey'=>",'relationship context must expose compact journey summary');
$contains($relationships,"foreach(array_slice((array)(\$memberJourney['recommendations']??[]),0,8) as \$rec)\$recommendations[]=\$rec;",'journey recommendations must enter the existing promoted recommendation stream');
$contains($relationships,'Member Journey recommendations use opaque member refs','relationship bridge must preserve identity privacy');
$missing($relationships,'coveted_member_journey_agent_context($admin','Operations bridge must not use the N+1 member context path');

$contains($operations,'$relationshipRecommendations=array_slice','Operations must continue consuming the canonical relationship recommendation stream');
$contains($operations,'$resultRecommendations=array_merge(','relationship recommendations must remain in the Agent-promoted event-results stream');
$contains($brain,"foreach (['event_planning','host_command','event_results'] as \$streamKey)",'Admin Agent brain must continue promoting that shared stream');
$contains($tasks,'function coveted_admin_agent_tasks_sync_opportunities','proactive Agent tasks must use the canonical task queue');

$contains($page,"require_once dirname(__DIR__) . '/app/member_journey_scan.php';",'System Admin journey workspace must load aggregate scan');
$contains($page,'coveted_member_journey_admin_index_fast($admin,100,$pdo)','System Admin queue must avoid a per-member metrics query loop');
$contains($page,'MEMBER JOURNEY INTELLIGENCE','System Admin journey workspace is required');
$contains($page,'NEXT BEST ACTION','journey workspace must display the current next-best action');
$contains($page,'JOURNEY TIMELINE','journey workspace must show canonical interaction history');
$contains($page,'Evidence, not personality inference','workspace must state the evidence boundary');
$contains($page,"coveted_redirect('/admin/system-preview.php?view=people')",'Sample Mode must not expose live member identities');
$missing($page,'coveted_member_journey_admin_index($admin','Admin workspace must not use the N+1 index path');

$contains($adminUi,"'/admin/member-journeys.php' => 'people'",'Admin shell must route Member Journeys safely in Sample Mode');
$contains($adminUi,"coveted_admin_nav_link(\$active, 'member-journeys', '/admin/member-journeys.php', 'Member Journeys')",'Member Journeys must be first-class People navigation');

fwrite(STDOUT,"Member Journey Intelligence contract verified.\n");
