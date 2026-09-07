<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{$v=@file_get_contents($root.'/'.ltrim($path,'/'));if($v===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $v;};
$contains=static function(string $content,string $needle,string $label):void{if(!str_contains($content,$needle)){fwrite(STDERR,"Member Relationship Intelligence contract failed: {$label}\n");exit(1);}};
$missing=static function(string $content,string $needle,string $label):void{if(str_contains($content,$needle)){fwrite(STDERR,"Member Relationship Intelligence contract failed: {$label}\n");exit(1);}};

$service=$read('app/member_relationships.php');
$page=$read('admin/member-relationships.php');
$operations=$read('app/operations.php');
$nav=$read('assets/js/member-relationships-nav-v1.js');
$loader=$read('assets/js/coveted.js');

$contains($service,'function coveted_member_relationship_group_snapshot','group relationship snapshot required');
$contains($service,'function coveted_member_relationship_agent_context','aggregate Admin Agent context required');
$contains($service,"gm.membership_status='active'",'only active memberships may drive relationship intelligence');
$contains($service,"u.status='active'",'inactive platform accounts must not drive recommendations');
$contains($service,"ea.status IN ('checked_in','attended','left_early')",'relationship evidence must use verified attendance only');
$contains($service,"e.status='completed'",'relationship evidence must use completed events');
$contains($service,"'drifting_members'",'member drift metric required');
$contains($service,"'reconnect_pairs'",'recurring reconnection metric required');
$contains($service,"'no_verified_overlap_pairs'",'participation breadth/no-overlap metric required');
$contains($service,"'participation_breadth'",'group participation breadth required');
$contains($service,"'attendance_concentration'",'attendance concentration required');
$contains($service,"'over_scheduled'",'event-fatigue/over-scheduling health state required');
$contains($service,"'fragmented'",'fragmented health state required');
$contains($service,"'under_engaged'",'under-engaged health state required');
$contains($service,"small_format_events",'small-format behavioral evidence required');
$contains($service,'No Mutual Reconnect choices','service must explicitly exclude private Mutual Reconnect choices');
$contains($service,"Broad Agent context intentionally contains no member identities or pair identities.",'Agent privacy boundary must be explicit');
$contains($service,"'privacy'=>'Aggregate group/event/member-journey relationship and Guest Mix signals only.",'Agent payload must document aggregate relationship/journey privacy');
$contains($service,"'member_journey'=>",'relationship Agent context must expose compact aggregate Member Journey state');
$missing($service,"'?member='",'relationship Agent bridge must not expose exact Member Journey identity routes');
$missing($service,'CREATE TABLE','relationship intelligence must not create a social-graph table');
$missing($service,'ALTER TABLE','relationship intelligence must not alter schema');
$missing($service,'INSERT INTO','relationship intelligence service must remain read-only');
$missing($service,'UPDATE ','relationship intelligence service must remain read-only');
$missing($service,'DELETE FROM','relationship intelligence service must remain read-only');

$contains($page,'coveted_require_system_admin();','workspace must be System Admin-only');
$contains($page,'coveted_system_sample_mode($admin,$pdo)','workspace must isolate Full System Sample Mode');
$contains($page,'No verified Coveted overlap','workspace must avoid claiming members have never met');
$contains($page,'not a claim that they have never met','workspace must state evidence boundary');
$contains($page,'Relationship intelligence, not social scoring','workspace must reject popularity scoring');
$contains($page,'broad Admin Agent context receives aggregate group-level signals','workspace must explain Agent identity boundary');

$contains($operations,"require_once __DIR__ . '/member_relationships.php';",'Operations must load relationship intelligence');
$contains($operations,'coveted_member_relationship_agent_context($actor, 20, $pdo)','Operations must request aggregate relationship context');
$contains($operations,"\$summary['member_relationships']",'Operations summary must expose relationship health');
$contains($operations,"\$summary['member_relationship_attention']",'relationship attention must contribute to Operations');
$contains($operations,'$relationshipRecommendations','relationship recommendations must be collected for the Agent-promoted stream');
$contains($operations,'$resultRecommendations=array_merge(','relationship recommendations must enter the combined Agent-promoted stream');
$contains($operations,'usort($resultRecommendations','combined Agent recommendations must preserve priority ordering');
$contains($operations,"'member_relationships' => \$memberRelationships",'Operations response must expose relationship context');

$contains($nav,"'/admin/member-relationships.php'",'Admin navigation must expose relationship intelligence');
$contains($nav,"shell.dataset.systemSample === '1'",'navigation must stay isolated in Sample Mode');
$contains($loader,'member-relationships-nav-v1.js','canonical JS loader must load relationship navigation');

fwrite(STDOUT,"Member Relationship + Reconnection Intelligence contract verified.\n");
