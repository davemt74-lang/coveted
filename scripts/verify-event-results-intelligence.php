<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $content=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($content===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);} return $content;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Event Results intelligence contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Event Results intelligence contract failed: {$label}\n");exit(1);}
};

$service=$read('app/event_results.php');
$page=$read('admin/event-results.php');
$operations=$read('app/operations.php');
$brain=$read('app/admin_agent_brain.php');
$cssIndex=$read('assets/css/coveted.css');
$css=$read('assets/css/event-results-v1.css');
$jsIndex=$read('assets/js/coveted.js');
$nav=$read('assets/js/event-results-nav-v1.js');

$contains($service,'function coveted_event_results_snapshot','canonical derived Event Results snapshot required');
$contains($service,'function coveted_event_results_agent_context','Event Results must feed Admin Agent context');
$contains($service,'function coveted_event_results_create_partner_followup','post-event result must connect to Partner CRM follow-up workflow');
$contains($service,'coveted_system_sample_mode($actor, $pdo)','sample mode must not expose live Event Results');
$contains($service,"event_rsvps",'results must use canonical RSVP state');
$contains($service,"event_attendance",'results must use canonical attendance state');
$contains($service,"repeat_attendees",'results must measure repeat attendance');
$contains($service,"reward_issuances",'results must use canonical reward issuance state');
$contains($service,"reward_claims",'results must use canonical claim state');
$contains($service,"event_production_items",'results must measure canonical Production execution');
$contains($service,"event_production_notes",'results must measure incident/closeout signals');
$contains($service,"coveted_venue_relationships_for_business",'results must include canonical partner relationship performance');
$contains($service,"coveted_partner_followups",'results must include Partner CRM follow-up state');
$contains($service,"coveted_partner_followup_add",'result follow-up action must reuse Partner CRM service');
$contains($service,"'privacy'=>'Aggregate event-result metrics only.", 'Agent context must explicitly remain aggregate-only');
$contains($service,"'score'=>",'Agent context must carry result score');
$contains($service,"'outcome'=>",'Agent context must carry deterministic result outcome');
$contains($service,"'recommendations'=>array_slice",'Agent context must expose actionable recommendations');
$missing($service,'CREATE TABLE','Event Results must not create a parallel result table');
$missing($service,'ALTER TABLE','Event Results must not alter runtime schema');
$missing($service,"'display_name'=>",'broad Agent event-result payload must not expose attendee/host identity fields');

$contains($page,'coveted_require_system_admin();','Event Results Admin page must require System Admin');
$contains($page,'coveted_system_sample_mode($admin, $pdo)','Event Results page must route Sample Mode away from live state');
$contains($page,'coveted_require_csrf();','Partner follow-up action must require CSRF');
$contains($page,'coveted_event_results_create_partner_followup','Event Results UI must use canonical follow-up service');
$contains($page,'POST-EVENT INTELLIGENCE','Event Results workspace identity required');
$contains($page,'RESULT SCORE','Event result scorecard required');
$contains($page,'HOST PERFORMANCE','Host execution results required');
$contains($page,'AGENT INTELLIGENCE','post-event Agent recommendations required');
$contains($page,'Event Opportunities','result-to-next-event loop required');

$contains($operations,"require_once __DIR__ . '/event_results.php';",'Operations must load Event Results intelligence');
$contains($operations,'coveted_event_results_agent_context($actor, 10, $pdo)','Operations must request post-event Agent context');
$contains($operations,"'event_results_attention'",'post-event result attention must contribute to Operations');
$contains($operations,"\$summary['event_results']",'Operations summary must expose Event Results');
$contains($operations,"'event_results' => \$eventResults",'Operations response must expose Event Results context');

$contains($brain,"foreach (['event_planning','host_command','event_results'",'Agent opportunity queue must promote Event Results alongside planning and Host Command while permitting additive intelligence streams');
$contains($brain,'review Event Results and post-event intelligence','Agent capability catalog must include post-event reasoning');
$contains($brain,'review post-event result signals','Operations capability must include Event Results oversight');
$contains($brain,"!str_starts_with(\$href, '/')",'Agent result routes must stay internal');

$contains($cssIndex,'event-results-v1.css','canonical CSS entrypoint must load Event Results styles');
$contains($css,'.cv-event-result-hero','Event Results scorecard styles required');
$contains($css,'.cv-event-host-result','host performance styles required');
$contains($jsIndex,'event-results-nav-v1.js','canonical JS entrypoint must load Event Results navigation');
$contains($nav,"path === '/admin/event-production.php'",'Event Production must gain Event Results handoff');
$contains($nav,"'/admin/event-results.php'",'Admin navigation must expose Event Results');

fwrite(STDOUT,"Event Results + Post-Event Intelligence contract verified.\n");