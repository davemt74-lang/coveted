<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{$v=@file_get_contents($root.'/'.ltrim($path,'/'));if($v===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $v;};
$contains=static function(string $c,string $n,string $label):void{if(!str_contains($c,$n)){fwrite(STDERR,"Event Proposals / Playbooks contract failed: {$label}\n");exit(1);}};
$missing=static function(string $c,string $n,string $label):void{if(str_contains($c,$n)){fwrite(STDERR,"Event Proposals / Playbooks contract failed: {$label}\n");exit(1);}};

$migration=$read('database/migrations/20260906_event_proposals_playbooks.sql');
$service=$read('app/event_proposals.php');
$opportunity=$read('admin/event-opportunities.php');
$proposals=$read('admin/event-proposals.php');
$playbooks=$read('admin/event-playbooks.php');
$operations=$read('app/operations.php');
$nav=$read('assets/js/event-opportunity-production-nav-v1.js');

foreach(['event_playbooks','event_proposals','event_proposal_updates','converted_event_id','partner_contact_ref','production_items_json','run_of_show_json'] as $needle){$contains($migration,$needle,'migration must define '.$needle);}
foreach(['supper_club','mystery_event','listening_session','artist_appearance','cocktail_social','wellness_session','private_table','local_discovery'] as $needle){$contains($migration,$needle,'default Playbook required: '.$needle);}

$contains($service,'function coveted_event_playbooks','canonical Playbook reader required');
$contains($service,'function coveted_event_playbook_save','custom Playbook management required');
$contains($service,'function coveted_event_proposal_create_from_opportunity','Opportunity must create Proposal first');
$contains($service,"status NOT IN ('converted','declined')",'active opportunity may not create duplicate live Proposal');
$contains($service,'function coveted_event_proposal_add_update','proposal activity timeline required');
$contains($service,'coveted_partner_interaction_add','partner proposal conversations must mirror into Partner CRM');
$contains($service,'function coveted_event_proposal_approve','explicit System Admin approval required');
$contains($service,"status='approved'",'approval state required');
$contains($service,'function coveted_event_proposal_convert','approved Proposal conversion required');
$contains($service,'coveted_event_create($admin','conversion must use canonical Event creation');
$contains($service,'coveted_event_set_location($admin','conversion must use canonical Event location service');
$contains($service,'coveted_event_production_seed_defaults','conversion must seed Event Production');
$contains($service,'coveted_event_production_create_item','Playbook tasks must extend Event Production');
$contains($service,'coveted_event_production_add_note','Playbook run of show / closeout must reach Production');
$contains($service,'function coveted_event_proposal_agent_context','Agent planning context required');
$contains($service,"'playbooks'=>",'Agent must receive active Playbooks');
$contains($service,"'proposals'=>",'Agent must receive proposal pipeline');
$contains($service,"'recommendations'=>",'Agent must receive proposal next actions');
$contains($service,'hosts never create or configure Events','Agent authority guidance must preserve host boundary');
$contains($service,'coveted_event_require_system_admin($admin)','all planning mutations must remain System Admin authority');
$contains($service,'coveted_system_sample_mode','Full System Sample Mode must remain read-only');
$missing($service,'CREATE TABLE','service must not create runtime schema');
$missing($service,'ALTER TABLE','service must not alter runtime schema');

$contains($opportunity,"'create_proposal'",'Opportunity UI must create Proposal instead of direct Event');
$contains($opportunity,'coveted_event_proposal_create_from_opportunity','Opportunity UI must use canonical Proposal service');
$contains($opportunity,'Event Playbook','Opportunity UI must choose a Playbook');
$missing($opportunity,"'create_draft'",'Opportunity UI must not bypass Proposal workflow');
$contains($proposals,'PARTNER CONVERSATION','Proposal workspace must support partner discussion');
$contains($proposals,'Feeds Partner CRM','proposal workspace must make CRM linkage explicit');
$contains($proposals,'Approve Proposal','proposal approval control required');
$contains($proposals,'Convert to Draft Event','approved Proposal conversion control required');
$contains($playbooks,'Playbooks are operational knowledge','Playbook workspace must expose Agent integration');

$contains($operations,"require_once __DIR__ . '/event_proposals.php';",'Operations brain must load Proposal / Playbook intelligence');
$contains($operations,'coveted_event_proposal_agent_context($actor, $pdo)','Operations brain must request proposal context');
$contains($operations,"'event_planning'",'Agent Operations summary must expose event planning');
$contains($operations,"'playbooks'=>array_slice",'Agent Operations summary must carry Playbook knowledge');
$contains($operations,"'recommendations'=>$planningRecommendations",'Agent Operations summary must carry proposal recommendations');
$contains($operations,"event_proposal_stalled",'stalled partner negotiations must contribute to Agent attention');
$contains($operations,"event_proposal_approved",'approved proposals must contribute to Agent attention');

$contains($nav,'Event Proposals','Admin navigation must expose Event Proposals');
$contains($nav,'Event Playbooks','Admin navigation must expose Event Playbooks');

fwrite(STDOUT,"Event Proposals + Playbooks contract verified.\n");
