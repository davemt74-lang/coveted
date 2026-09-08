<?php
declare(strict_types=1);

require_once __DIR__ . '/group_relationship_planning.php';

/**
 * Apply the final conservative eligibility boundary to a derived relationship
 * plan. A member missing from the bounded Member Journey scan is not assumed
 * eligible; the member is held out until current pacing evidence is available.
 *
 * @return array<string,mixed>
 */
function coveted_group_relationship_plan_guarded(array $admin,string $groupRef,?PDO $pdo=null): array
{
    $pdo=coveted_group_relationship_planning_require_admin($admin,$pdo);
    $plan=coveted_group_relationship_plan($admin,$groupRef,$pdo);

    $known=[];
    foreach(coveted_member_journey_scan_rows($pdo,200) as $row){
        $id=(int)($row['id']??0);
        if($id>0)$known[$id]=true;
    }

    $unknownHeld=0;
    $segments=['reconnect'=>0,'first_event'=>0,'reliable_recent'=>0,'balanced'=>0,'paced'=>0,'hold'=>(int)($plan['lifecycle_holds']??0)];
    $candidates=[];
    foreach((array)($plan['invite_candidates']??[]) as $candidate){
        $candidate=(array)$candidate;
        $id=(int)($candidate['user_id']??0);
        $segment=(string)($candidate['segment']??'balanced');
        if($id<1 || !isset($known[$id])){
            if($segment!=='paced')$unknownHeld++;
            $segment='paced';
            $candidate['segment']='paced';
            $candidate['reason']='Current bounded Member Journey pacing evidence is unavailable; hold this member out until the Admin reviews current Journey state.';
        }
        $segments[$segment]=($segments[$segment]??0)+1;
        $candidates[]=$candidate;
    }

    $eligible=(int)$segments['reconnect']+(int)$segments['first_event']+(int)$segments['reliable_recent']+(int)$segments['balanced'];
    $capacity=max(0,min((int)($plan['event']['capacity']??0),$eligible));
    if($eligible===0)$capacity=0;
    $target=coveted_group_relationship_planning_target_mix((string)($plan['objective']['key']??'balanced'),$capacity,$segments);
    $targetTotal=array_sum(array_map('intval',$target));

    $plan['guest_mix']=$segments;
    $plan['invite_candidates']=$candidates;
    $plan['paced_members']=(int)$segments['paced'];
    $plan['unknown_journey_holds']=$unknownHeld;
    $plan['target_mix']=$target;
    $plan['event']['capacity']=$capacity;
    $plan['event']['recommended']=!empty($plan['event']['recommended']) && $capacity>0 && $targetTotal>0;
    if(!$plan['event']['recommended'])$plan['event']['suggested_start_at']='';
    $plan['event']['concept']=(string)($plan['objective']['detail']??'')
        .' Target invitation mix: '.(int)$target['reconnect'].' reconnect · '.(int)$target['first_event'].' first-event · '.(int)$target['reliable_recent'].' reliable recent · '.(int)$target['balanced'].' balanced.';
    if($unknownHeld>0){
        $plan['evidence'].=' '.$unknownHeld.' member'.($unknownHeld===1?' is':'s are').' held because current bounded Journey pacing evidence is unavailable.';
    }
    return $plan;
}

/** @return array<string,mixed> */
function coveted_group_relationship_planning_guarded_agent_context(array $admin,int $limit=20,?PDO $pdo=null): array
{
    $pdo=coveted_group_relationship_planning_require_admin($admin,$pdo);
    $plans=[];$recommendations=[];$attention=0;
    foreach(array_slice(coveted_member_relationship_groups($admin,$pdo),0,max(1,min(40,$limit))) as $group){
        try{$plan=coveted_group_relationship_plan_guarded($admin,(string)$group['public_id'],$pdo);}catch(Throwable $e){error_log('Guarded Group Relationship Plan unavailable: '.$e->getMessage());continue;}
        $objective=(array)$plan['objective'];$event=(array)$plan['event'];$metrics=(array)$plan['metrics'];
        if((int)$objective['priority']===1 || !empty($event['recommended']))$attention++;
        $plans[]=[
            'group_ref'=>(string)$plan['group']['public_id'],'group'=>(string)$plan['group']['name'],'health'=>(string)$plan['health'],
            'objective'=>(string)$objective['key'],'objective_label'=>(string)$objective['label'],'event_recommended'=>!empty($event['recommended']),
            'event_type'=>(string)$event['event_type'],'social_format'=>(string)$event['social_format'],'capacity'=>(int)$event['capacity'],
            'participation_breadth'=>(float)$metrics['participation_breadth'],'drifting_members'=>(int)$metrics['drifting_members'],'under_engaged_members'=>(int)$metrics['under_engaged_members'],
            'paced_members'=>(int)$plan['paced_members'],'unknown_journey_holds'=>(int)$plan['unknown_journey_holds'],'future_events'=>(int)$plan['future_events'],
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
        'privacy'=>'Aggregate guarded group planning only: no member names, candidate identities, contact details, private messages, personality inference or public rankings enter broad Agent context.',
        'authority'=>'Read-only planning intelligence. System Admin alone decides whether to create, approve and convert an Event Proposal into a canonical Event.',
    ];
}
