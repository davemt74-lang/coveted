<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $value=@file_get_contents($root.'/'.ltrim($path,'/'));
    if($value===false){fwrite(STDERR,"Missing required file: {$path}\n");exit(1);}return $value;
};
$contains=static function(string $content,string $needle,string $label):void{
    if(!str_contains($content,$needle)){fwrite(STDERR,"Group Relationship Planning contract failed: {$label}\n");exit(1);}
};
$missing=static function(string $content,string $needle,string $label):void{
    if(str_contains($content,$needle)){fwrite(STDERR,"Group Relationship Planning contract failed: {$label}\n");exit(1);}
};

$planning=$read('app/group_relationship_planning.php');
$guard=$read('app/group_relationship_planning_guard.php');
$workspace=$read('admin/group-relationship-planning.php');
$opportunities=$read('app/event_opportunities.php');
$proposals=$read('app/event_proposals.php');
$relationships=$read('app/member_relationships.php');
$learning=$read('app/event_learning.php');

$contains($planning,"require_once __DIR__ . '/member_relationships.php';",'planning must consume canonical Group Health / relationship intelligence');
$contains($planning,"require_once __DIR__ . '/event_learning.php';",'planning must consume canonical completed Event Learning');
$contains($planning,'coveted_member_journey_scan_rows($pdo,200)','planning must consume bounded Member Journey pacing evidence');
$contains($planning,"\$health === 'over_scheduled'",'over-scheduled groups must produce a no-new-Event pacing recommendation');
$contains($planning,"'reconnect'",'reconnection planning objective is required');
$contains($planning,"'widen_circle'",'participation-breadth planning objective is required');
$contains($planning,"'first_event'",'first-event guest-mix segment is required');
$contains($planning,"'reliable_recent'",'reliable recent-participant guest-mix segment is required');
$contains($planning,"\$action==='pause_invitations'",'Member Journey pacing must exclude over-contacted members');
$contains($planning,"in_array((string)\$row['lifecycle_state'],['paused','alumni'],true)",'lifecycle holds must be excluded from invitation targets');
$contains($planning,'coveted_event_learning_dataset($admin,180,$pdo)','partner/location fit must use completed Event Learning');
$contains($planning,'coveted_event_learning_metrics($rows)','location fit must use canonical Event Learning metrics');
$contains($planning,"SELECT COUNT(*) FROM events WHERE group_id=? AND status IN ('draft','published','closed')",'existing future Event cadence must block another proposal recommendation');
$contains($planning,"'event-opportunity:'",'proposal handoff must target the existing Event Opportunity / Proposal workflow');
$contains($planning,"'task_sync'=>true",'planning recommendations must be eligible for the existing proactive Agent task queue');
$contains($planning,'Broad Agent context exposes aggregate counts only','Agent privacy boundary must remain aggregate-only');
$contains($planning,'System Admin alone decides whether to create, approve and convert an Event Proposal','System Admin event authority must be explicit');
$missing($planning,'INSERT INTO events','planning service must not create Events directly');
$missing($planning,'coveted_event_create(','planning service must not call the canonical Event creator directly');
$missing($planning,'coveted_event_invite_user(','planning service must not send invitations');
$missing($planning,'INSERT INTO event_invitations','planning service must not bypass invitation services');
$missing($planning,"'fit_score'",'Group Planning must not create per-member fit scores');
$missing($planning,"'member_score'",'Group Planning must not create hidden member scores');

$contains($guard,"require_once __DIR__ . '/group_relationship_planning.php';",'guard must wrap the canonical planning read layer');
$contains($guard,'function coveted_group_relationship_plan_guarded','conservative guarded plan is required');
$contains($guard,'coveted_member_journey_scan_rows($pdo,200)','guard must re-read bounded current Journey evidence');
$contains($guard,'!isset($known[$id])','members missing from bounded Journey evidence must not be assumed eligible');
$contains($guard,'Current bounded Member Journey pacing evidence is unavailable','unknown Journey state must produce an explicit hold reason');
$contains($guard,'if($eligible===0)$capacity=0;','zero eligible members must force zero capacity');
$contains($guard,"!empty(\$plan['event']['recommended']) && \$capacity>0 && \$targetTotal>0",'proposal recommendation must require eligible capacity and target seats');
$contains($guard,'function coveted_group_relationship_planning_guarded_agent_context','Agent context must use the guarded plan');
$contains($guard,"'task_sync'=>true",'guarded planning recommendations must remain eligible for proactive tasks');
$contains($guard,'Aggregate guarded group planning only','guarded Agent context must remain aggregate-only');
$missing($guard,'INSERT INTO events','guard must not create Events');
$missing($guard,'coveted_event_create(','guard must not create Events through canonical creator');
$missing($guard,'coveted_event_invite_user(','guard must not send invitations');

$contains($workspace,"require_once dirname(__DIR__) . '/app/group_relationship_planning_guard.php';",'workspace must load guarded planning');
$contains($workspace,'coveted_require_system_admin();','planning workspace must require System Admin');
$contains($workspace,'coveted_require_csrf();','proposal creation must enforce CSRF');
$contains($workspace,'coveted_group_relationship_plan_guarded($admin,$groupRef,$pdo)','workspace must re-read guarded live planning evidence before proposal creation');
$contains($workspace,'coveted_event_opportunity_by_key(','workspace must revalidate canonical Event Opportunity availability');
$contains($workspace,'coveted_event_playbook_by_ref($admin,$playbookRef,$pdo)','workspace must validate the chosen Playbook before creating a proposal');
$contains($workspace,"(string)\$playbook['status']!=='active'",'workspace must reject inactive Playbooks before proposal mutation');
$contains($workspace,'coveted_event_proposal_create_from_opportunity(','workspace must use the existing canonical Event Proposal creator');
$contains($workspace,'coveted_event_proposal_update(','new proposals may be refined only through the canonical Proposal service');
$contains($workspace,"if(!empty(\$result['created']))",'existing Admin-edited proposals must not be silently overwritten');
$contains($workspace,'No Event or invitation will be created yet.','proposal-only authority boundary must be visible');
$contains($workspace,'Paced, lifecycle-hold and unknown-Journey members are excluded','private target mix must explain all pacing exclusions');
$missing($workspace,'coveted_event_create(','workspace must not create Events directly');
$missing($workspace,'coveted_event_invite_user(','workspace must not send invitations');
$missing($workspace,'INSERT INTO event_proposals','workspace must not bypass canonical Proposal service');

$contains($opportunities,"require_once __DIR__ . '/group_relationship_planning_guard.php';",'Event Opportunity context must load guarded Group Relationship Planning');
$contains($opportunities,'coveted_group_relationship_planning_guarded_agent_context($admin,20,$pdo)','guarded planning must enter the existing Event Opportunity / Operations stream');
$contains($opportunities,"'group_relationship_planning'=>[",'Operations-facing context must retain aggregate planning details');
$contains($opportunities,"array_merge(array_slice(\$items,0,12),array_slice((array)(\$planning['recommendations']??[]),0,12))",'planning recommendations must merge into the canonical opportunity stream');

$contains($proposals,'function coveted_event_proposal_create_from_opportunity','canonical proposal handoff must remain available');
$contains($relationships,'function coveted_member_relationship_group_snapshot','canonical Group Health source must remain available');
$contains($learning,'function coveted_event_learning_dataset','canonical Event Learning source must remain available');

fwrite(STDOUT,"Group Relationship Planning contract verified.\n");
