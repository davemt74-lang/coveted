<?php
declare(strict_types=1);

require_once __DIR__ . '/member_relationships.php';
require_once __DIR__ . '/event_learning.php';
require_once __DIR__ . '/member_journey_scan.php';
require_once __DIR__ . '/system_sample_data.php';

/**
 * Group Relationship Planning is a read-only orchestration layer. It combines
 * verified Group Health, Member Journey, completed Event Learning and current
 * partner/location state to answer what the next Event should accomplish.
 * It never creates Events, sends invitations, or persists member scores.
 */
function coveted_group_relationship_planning_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Group Relationship Planning.');
    }
    return $pdo;
}

function coveted_group_relationship_planning_objective(string $health, array $metrics): array
{
    $drifting=(int)($metrics['drifting_members']??0);
    $under=(int)($metrics['under_engaged_members']??0);
    $pairs=(int)($metrics['reconnect_pairs']??0);
    return match (true) {
        $health === 'over_scheduled' => [
            'key'=>'pace','label'=>'Protect relationship pacing',
            'detail'=>'Do not add another Event yet. Recent Event frequency is high relative to participation breadth; work the current relationship queue before scheduling more invitations.',
            'social_format'=>'No new Event / pacing hold','priority'=>1,'should_plan'=>false,
        ],
        $health === 'fading' || $drifting > 0 || $pairs > 0 => [
            'key'=>'reconnect','label'=>'Reconnect established relationships',
            'detail'=>'Use a smaller, lower-pressure gathering to bring previously engaged members back into the same room without increasing invitation pressure broadly.',
            'social_format'=>'Small-table reconnection gathering','priority'=>1,'should_plan'=>true,
        ],
        $health === 'fragmented' || $health === 'under_engaged' || $under > 0 => [
            'key'=>'widen_circle','label'=>'Widen the participating circle',
            'detail'=>'Use the next Event to mix reliable recent participants with active members who have limited verified participation.',
            'social_format'=>'Hosted small-group mixer / shared table','priority'=>1,'should_plan'=>true,
        ],
        $health === 'growing' => [
            'key'=>'strengthen_growth','label'=>'Strengthen healthy growth',
            'detail'=>'Keep the format proven and manageable while intentionally reserving room for first-event and reconnect opportunities.',
            'social_format'=>'Repeatable hosted social gathering','priority'=>2,'should_plan'=>true,
        ],
        default => [
            'key'=>'balanced','label'=>'Maintain a balanced relationship cadence',
            'detail'=>'Plan a familiar, manageable gathering with a balanced mix of recent participants, reconnection candidates and first-event members.',
            'social_format'=>'Balanced hosted gathering','priority'=>2,'should_plan'=>true,
        ],
    };
}

/** @return array<int,array<string,mixed>> */
function coveted_group_relationship_planning_learning_rows(array $admin, string $groupRef, PDO $pdo): array
{
    $rows=[];
    foreach(coveted_event_learning_dataset($admin,180,$pdo) as $row){
        if((string)($row['group_ref']??'')===$groupRef)$rows[]=$row;
    }
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function coveted_group_relationship_planning_locations(array $learningRows, PDO $pdo): array
{
    if(!$learningRows)return [];
    $active=[];
    foreach($pdo->query("SELECT l.id,l.public_id,l.name,l.city,l.region,l.timezone,l.business_id,b.public_id AS business_ref,b.name AS business_name FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.status='active' AND b.status='active'")->fetchAll() as $row){
        $active[(int)$row['id']]=$row;
    }
    $byLocation=[];
    foreach($learningRows as $row){
        $id=(int)($row['location_id']??0);
        if($id<1 || !isset($active[$id]))continue;
        $byLocation[$id][]=$row;
    }
    $statusRank=['home_venue'=>5,'preferred_partner'=>4,'partner'=>3,'event_venue'=>2,'new'=>1,''=>0];
    $items=[];
    foreach($byLocation as $id=>$rows){
        $metrics=coveted_event_learning_metrics($rows);
        $location=$active[$id];
        $latest=$rows[0]??[];
        $status=(string)($latest['relationship_status']??'event_venue');
        $items[]=[
            'location_id'=>$id,'location_ref'=>(string)$location['public_id'],'location_name'=>(string)$location['name'],
            'business_id'=>(int)$location['business_id'],'business_ref'=>(string)$location['business_ref'],'business_name'=>(string)$location['business_name'],
            'city'=>(string)($location['city']??''),'region'=>(string)($location['region']??''),'timezone'=>(string)($location['timezone']??'America/Phoenix'),
            'relationship_status'=>$status,'relationship_rank'=>(int)($statusRank[$status]??0),
            'events'=>(int)$metrics['events'],'avg_result'=>(float)$metrics['avg_score'],'verified'=>(int)$metrics['verified'],
            'attendance_rate'=>(float)$metrics['attendance_rate'],'repeat_rate'=>(float)$metrics['repeat_rate'],'claim_rate'=>(float)$metrics['claim_rate'],
            'recommended_capacity'=>(int)$metrics['recommended_capacity'],'confidence'=>(string)($metrics['confidence']['label']??'low'),
        ];
    }
    usort($items,static function(array $a,array $b):int{
        return ((int)$b['relationship_rank']<=> (int)$a['relationship_rank'])
            ?: ((float)$b['avg_result']<=> (float)$a['avg_result'])
            ?: ((int)$b['events']<=> (int)$a['events'])
            ?: strcmp((string)$a['location_name'],(string)$b['location_name']);
    });
    return $items;
}

function coveted_group_relationship_planning_start(string $timezone, array $learningMetrics, int $minimumDays=12): string
{
    try{$zone=coveted_require_timezone($timezone);}catch(Throwable){$zone=new DateTimeZone('America/Phoenix');}
    $day=4;$hour=19;
    $slot=(array)($learningMetrics['best_slot']??[]);
    $key=trim((string)($slot['key']??''));
    if(preg_match('/^([1-7]):([0-9]{1,2})$/',$key,$m)){
        $day=max(1,min(7,(int)$m[1]));$hour=max(10,min(21,(int)$m[2]));
    }
    $candidate=(new DateTimeImmutable('now',$zone))->modify('+'.max(7,$minimumDays).' days')->setTime($hour,0);
    for($i=0;$i<7 && (int)$candidate->format('N')!==$day;$i++)$candidate=$candidate->modify('+1 day');
    return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** @return array{segments:array<string,int>,paced:int,holds:int,candidates:array<int,array<string,mixed>>} */
function coveted_group_relationship_planning_guest_mix(array $relationship, PDO $pdo): array
{
    $memberIds=[];
    foreach((array)($relationship['members']??[]) as $member)$memberIds[(int)$member['user_id']]=true;
    $journeys=[];
    foreach(coveted_member_journey_scan_rows($pdo,200) as $row){
        $id=(int)($row['id']??0);if(isset($memberIds[$id]))$journeys[$id]=$row;
    }
    $holds=[];
    if($memberIds){
        $ids=implode(',',array_map('intval',array_keys($memberIds)));
        try{
            foreach($pdo->query("SELECT user_id,lifecycle_state FROM membership_lifecycle WHERE user_id IN ({$ids})")->fetchAll() as $row){
                if(in_array((string)$row['lifecycle_state'],['paused','alumni'],true))$holds[(int)$row['user_id']]=true;
            }
        }catch(Throwable){}
    }
    $segments=['reconnect'=>0,'first_event'=>0,'reliable_recent'=>0,'balanced'=>0,'paced'=>0,'hold'=>0];
    $candidates=[];
    foreach((array)($relationship['members']??[]) as $member){
        $id=(int)$member['user_id'];
        if(isset($holds[$id])){$segments['hold']++;continue;}
        $journey=$journeys[$id]??null;
        $segment='balanced';$reason='Active group member available for a balanced invitation mix.';
        if($journey){
            $decision=coveted_member_journey_decision($journey);
            $action=(string)($decision['action']??'hold_steady');
            $paced=$action==='pause_invitations' || (int)($journey['invitations_30d']??0)>=3 || (int)($journey['future_invitations']??0)>=2;
            if($paced){$segment='paced';$reason='Recent invitation or future-event pressure requires pacing.';}
            elseif(in_array($action,['recover_after_no_show','reconnect_small_format','reconnect'],true)){$segment='reconnect';$reason=(string)$decision['evidence'];}
            elseif($action==='first_event_fit' || (int)($journey['verified_365d']??0)===0){$segment='first_event';$reason=(string)$decision['evidence'];}
            elseif((int)($journey['verified_90d']??0)>0){$segment='reliable_recent';$reason=(string)$decision['evidence'];}
            else{$reason=(string)($decision['evidence']??$reason);}
        }else{
            if((int)($member['verified_events']??0)===0){$segment='first_event';$reason='No verified group attendance in the available relationship history.';}
            elseif((int)($member['verified_90d']??0)===0){$segment='reconnect';$reason='Prior verified group attendance with no verified attendance in 90 days.';}
            elseif((int)($member['verified_90d']??0)>0){$segment='reliable_recent';$reason='Recent verified group participation.';}
        }
        $segments[$segment]=($segments[$segment]??0)+1;
        $candidates[]=[
            'user_id'=>$id,'display_name'=>(string)$member['display_name'],'segment'=>$segment,'reason'=>$reason,
            'verified_90d'=>(int)($member['verified_90d']??0),'last_verified_at'=>(string)($member['last_verified_at']??''),
        ];
    }
    $order=['reconnect'=>1,'first_event'=>2,'reliable_recent'=>3,'balanced'=>4,'paced'=>5];
    usort($candidates,static fn(array $a,array $b):int=>((int)($order[$a['segment']]??9)<=> (int)($order[$b['segment']]??9)) ?: strcmp((string)$a['display_name'],(string)$b['display_name']));
    return ['segments'=>$segments,'paced'=>(int)$segments['paced'],'holds'=>(int)$segments['hold'],'candidates'=>$candidates];
}

/** @return array<string,int> */
function coveted_group_relationship_planning_target_mix(string $objective, int $capacity, array $segments): array
{
    if($capacity<1)return ['reconnect'=>0,'first_event'=>0,'reliable_recent'=>0,'balanced'=>0];
    $weights=match($objective){
        'reconnect'=>['reconnect'=>.45,'first_event'=>.15,'reliable_recent'=>.30,'balanced'=>.10],
        'widen_circle'=>['reconnect'=>.20,'first_event'=>.40,'reliable_recent'=>.30,'balanced'=>.10],
        'strengthen_growth'=>['reconnect'=>.20,'first_event'=>.25,'reliable_recent'=>.40,'balanced'=>.15],
        default=>['reconnect'=>.25,'first_event'=>.25,'reliable_recent'=>.35,'balanced'=>.15],
    };
    $target=[];$assigned=0;
    foreach($weights as $segment=>$weight){
        $available=(int)($segments[$segment]??0);
        $value=min($available,(int)floor($capacity*$weight));
        $target[$segment]=$value;$assigned+=$value;
    }
    $remaining=max(0,$capacity-$assigned);
    foreach(['reconnect','first_event','reliable_recent','balanced'] as $segment){
        if($remaining<1)break;
        $available=max(0,(int)($segments[$segment]??0)-(int)$target[$segment]);
        $add=min($remaining,$available);$target[$segment]+=$add;$remaining-=$add;
    }
    return $target;
}

/** @return array<string,mixed> */
function coveted_group_relationship_plan(array $admin,string $groupRef,?PDO $pdo=null): array
{
    $pdo=coveted_group_relationship_planning_require_admin($admin,$pdo);
    $relationship=coveted_member_relationship_group_snapshot($admin,$groupRef,$pdo);
    $group=(array)$relationship['group'];$metrics=(array)$relationship['metrics'];
    $objective=coveted_group_relationship_planning_objective((string)$relationship['health'],$metrics);
    $learningRows=coveted_group_relationship_planning_learning_rows($admin,(string)$group['public_id'],$pdo);
    $learning=coveted_event_learning_metrics($learningRows);
    $locations=coveted_group_relationship_planning_locations($learningRows,$pdo);
    $location=$locations[0]??null;
    $guestMix=coveted_group_relationship_planning_guest_mix($relationship,$pdo);

    $futureStmt=$pdo->prepare("SELECT COUNT(*) FROM events WHERE group_id=? AND status IN ('draft','published','closed') AND starts_at>UTC_TIMESTAMP()");
    $futureStmt->execute([(int)$group['id']]);$futureEvents=(int)$futureStmt->fetchColumn();

    $baseCapacity=(int)($learning['recommended_capacity']??0);
    if($baseCapacity<1)$baseCapacity=min(24,max(10,(int)ceil(max(1,(int)$metrics['active_members'])*.55)));
    $capacity=match((string)$objective['key']){
        'reconnect'=>min(18,max(10,min($baseCapacity,(int)ceil(max(1,(int)$metrics['active_members'])*.45)))),
        'widen_circle'=>min(20,max(12,min($baseCapacity,(int)ceil(max(1,(int)$metrics['active_members'])*.55)))),
        'pace'=>0,
        default=>min(30,max(12,$baseCapacity)),
    };
    $eligible=max(0,(int)$metrics['active_members']-(int)$guestMix['paced']-(int)$guestMix['holds']);
    if($capacity>0)$capacity=min($capacity,max(1,$eligible));
    $targetMix=coveted_group_relationship_planning_target_mix((string)$objective['key'],$capacity,(array)$guestMix['segments']);

    $eventType=match((string)$objective['key']){
        'reconnect','widen_circle'=>'private_table',
        default=>(string)(($learning['best_event_type']['key']??'regular') ?: 'regular'),
    };
    if(!in_array($eventType,['regular','mystery','private_table','member_plus_one','session'],true))$eventType='regular';
    $playbookKey=match($eventType){'private_table'=>'private_table','mystery'=>'mystery_event','session'=>'wellness_session',default=>'supper_club'};
    $timezone=$location?(string)$location['timezone']:'America/Phoenix';
    $start=$capacity>0?coveted_group_relationship_planning_start($timezone,$learning,12):'';
    $opportunityKey=$location?'event-opportunity:'.(string)$group['public_id'].':'.(string)$location['location_ref']:'';
    $canPropose=!empty($objective['should_plan']) && $futureEvents===0 && $location!==null && $capacity>0;

    $evidence=[
        ucfirst(str_replace('_',' ',(string)$relationship['health'])).' group health',
        number_format((float)$metrics['participation_breadth'],1).'% participation breadth',
        (int)$metrics['drifting_members'].' drifting',
        (int)$metrics['under_engaged_members'].' under-engaged',
        (int)$guestMix['paced'].' paced',
        (int)$learning['events'].' completed Events in learning set',
    ];
    if($location)$evidence[]=(string)$location['business_name'].' / '.(string)$location['location_name'].' · '.ucfirst(str_replace('_',' ',(string)$location['relationship_status'])).' · '.(int)$location['events'].' learned Events';
    if($futureEvents>0)$evidence[]=$futureEvents.' future Event'.($futureEvents===1?'':'s').' already scheduled';

    return [
        'group'=>$group,'health'=>(string)$relationship['health'],'metrics'=>$metrics,'objective'=>$objective,
        'event'=>[
            'recommended'=>$canPropose,'event_type'=>$eventType,'social_format'=>(string)$objective['social_format'],'capacity'=>$capacity,
            'suggested_start_at'=>$start,'timezone'=>$timezone,'playbook_key'=>$playbookKey,'opportunity_key'=>$opportunityKey,
            'title'=>(string)$group['name'].' · '.(string)$objective['label'],
            'concept'=>(string)$objective['detail'].' Target invitation mix: '.(int)$targetMix['reconnect'].' reconnect · '.(int)$targetMix['first_event'].' first-event · '.(int)$targetMix['reliable_recent'].' reliable recent · '.(int)$targetMix['balanced'].' balanced.',
        ],
        'target_mix'=>$targetMix,'guest_mix'=>(array)$guestMix['segments'],'invite_candidates'=>(array)$guestMix['candidates'],
        'paced_members'=>(int)$guestMix['paced'],'lifecycle_holds'=>(int)$guestMix['holds'],
        'location'=>$location,'location_candidates'=>array_slice($locations,0,8),'learning'=>$learning,'future_events'=>$futureEvents,
        'evidence'=>implode(' · ',$evidence).'.',
        'privacy'=>'Private planning uses verified Coveted attendance and Member Journey operational evidence. Broad Agent context exposes aggregate counts only; member names and candidate identities remain in the System Admin workspace. No personality inference or public member ranking is used.',
        'authority'=>'This is a planning recommendation only. System Admin may create an Event Proposal from the recommendation, then separately approve and convert that proposal through the existing canonical Event workflow.',
    ];
}

/** @return array<string,mixed> */
function coveted_group_relationship_planning_agent_context(array $admin,int $limit=20,?PDO $pdo=null): array
{
    $pdo=coveted_group_relationship_planning_require_admin($admin,$pdo);
    $plans=[];$recommendations=[];$attention=0;
    foreach(array_slice(coveted_member_relationship_groups($admin,$pdo),0,max(1,min(40,$limit))) as $group){
        try{$plan=coveted_group_relationship_plan($admin,(string)$group['public_id'],$pdo);}catch(Throwable $e){error_log('Group Relationship Plan unavailable: '.$e->getMessage());continue;}
        $objective=(array)$plan['objective'];$event=(array)$plan['event'];$metrics=(array)$plan['metrics'];
        if((int)$objective['priority']===1 || !empty($event['recommended']))$attention++;
        $plans[]=[
            'group_ref'=>(string)$plan['group']['public_id'],'group'=>(string)$plan['group']['name'],'health'=>(string)$plan['health'],
            'objective'=>(string)$objective['key'],'objective_label'=>(string)$objective['label'],'event_recommended'=>!empty($event['recommended']),
            'event_type'=>(string)$event['event_type'],'social_format'=>(string)$event['social_format'],'capacity'=>(int)$event['capacity'],
            'participation_breadth'=>(float)$metrics['participation_breadth'],'drifting_members'=>(int)$metrics['drifting_members'],'under_engaged_members'=>(int)$metrics['under_engaged_members'],
            'paced_members'=>(int)$plan['paced_members'],'future_events'=>(int)$plan['future_events'],
            'target_mix'=>(array)$plan['target_mix'],
            'partner'=>(string)($plan['location']['business_name']??''),'location'=>(string)($plan['location']['location_name']??''),
            'learning_confidence'=>(string)($plan['learning']['confidence']['label']??'low'),
            'href'=>'/admin/group-relationship-planning.php?group='.rawurlencode((string)$plan['group']['public_id']),
        ];
        $recommendations[]=[
            'priority'=>(int)$objective['priority'],'key'=>'group-relationship-plan-'.(string)$plan['group']['public_id'],'category'=>'Relationship Planning',
            'title'=>(string)$objective['label'].' for '.(string)$plan['group']['name'],
            'detail'=>!empty($event['recommended'])
                ? 'Review the recommended Event format, partner/location and target invitation mix, then decide whether to create a proposal.'
                : (string)$objective['detail'],
            'evidence'=>(string)$plan['evidence'],
            'href'=>'/admin/group-relationship-planning.php?group='.rawurlencode((string)$plan['group']['public_id']),
            'task_sync'=>true,
        ];
    }
    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return [
        'available'=>true,'plans'=>$plans,'recommendations'=>array_slice($recommendations,0,20),'attention'=>$attention,
        'privacy'=>'Aggregate group planning only: no member names, candidate identities, contact details, private messages, personality inference or public rankings enter broad Agent context.',
        'authority'=>'Read-only planning intelligence. System Admin alone decides whether to create, approve and convert an Event Proposal into a canonical Event.',
    ];
}
