<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/event_management.php';
require_once __DIR__ . '/system_sample_data.php';
require_once __DIR__ . '/event_learning.php';

/**
 * Event opportunities are a deterministic read model built from canonical
 * Coveted relationship/event/member data. No recommendation mutates state.
 */
function coveted_event_opportunity_key(string $groupRef, string $locationRef): string
{
    return 'event-opportunity:' . $groupRef . ':' . $locationRef;
}

/** @return array<string,int> */
function coveted_event_opportunity_active_perks(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT CONCAT(group_id, ':', location_id) AS relationship_key, COUNT(*) AS active_perks
             FROM partner_perks
             WHERE status = 'active'
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             GROUP BY group_id, location_id"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $result=[];
    foreach($rows as $row)$result[(string)$row['relationship_key']]=(int)$row['active_perks'];
    return $result;
}

/** @return array<string,int> */
function coveted_event_opportunity_active_campaigns(PDO $pdo): array
{
    try {
        $rows=$pdo->query(
            "SELECT CONCAT(e.group_id, ':', el.location_id) AS relationship_key,
                    COUNT(DISTINCT c.id) AS active_campaigns
             FROM events e
             JOIN event_locations el ON el.event_id = e.id AND el.location_id IS NOT NULL
             JOIN locations l ON l.id = el.location_id
             JOIN campaigns c
               ON c.business_id = l.business_id
              AND c.status = 'active'
              AND (c.location_id IS NULL OR c.location_id = l.id)
              AND (c.starts_at IS NULL OR c.starts_at <= NOW())
              AND (c.ends_at IS NULL OR c.ends_at >= NOW())
             WHERE e.status IN ('published','closed','completed')
             GROUP BY e.group_id, el.location_id"
        )->fetchAll();
    } catch(Throwable){return[];}
    $result=[];foreach($rows as $row)$result[(string)$row['relationship_key']]=(int)$row['active_campaigns'];return$result;
}

function coveted_event_opportunity_suggested_start(string $timezone,int $minimumDays=12):string
{
    try{$zone=coveted_require_timezone($timezone);}catch(Throwable){$zone=new DateTimeZone('America/Phoenix');}
    $candidate=(new DateTimeImmutable('now',$zone))->modify('+'.max(7,$minimumDays).' days')->setTime(19,0);
    $day=(int)$candidate->format('N');if($day<4||$day>6)$candidate=$candidate->modify('next Thursday');
    return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** @return array<int,array<string,mixed>> */
function coveted_event_opportunities(array $admin,?PDO $pdo=null):array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo??=coveted_db();if(coveted_system_sample_mode($admin,$pdo))return[];

    // Start from real canonical event/location history, then LEFT JOIN the
    // stored CRM relationship. This mirrors Venue Relationships: a completed
    // event creates an inferred Event Venue even before someone saves a CRM row.
    $rows=$pdo->query(
        "SELECT
            g.id AS group_id,g.public_id AS group_ref,g.name AS group_name,g.city AS group_city,
            l.id AS location_id,l.public_id AS location_ref,l.name AS location_name,l.city AS location_city,l.region AS location_region,l.timezone AS location_timezone,
            b.id AS business_id,b.public_id AS business_ref,b.name AS business_name,
            vr.relationship_status AS stored_relationship_status,COALESCE(vr.benefits_enabled,0) AS benefits_enabled,COALESCE(vr.mystery_events_enabled,0) AS mystery_events_enabled,vr.partner_since,
            (SELECT COUNT(*) FROM group_memberships gm WHERE gm.group_id=g.id AND gm.membership_status='active') AS active_members,
            (SELECT COUNT(*) FROM events e2 JOIN event_locations el2 ON el2.event_id=e2.id AND el2.location_id=l.id WHERE e2.group_id=g.id AND e2.status='completed') AS completed_events,
            (SELECT COUNT(*) FROM events e3 JOIN event_locations el3 ON el3.event_id=e3.id AND el3.location_id=l.id WHERE e3.group_id=g.id AND e3.status IN ('published','closed') AND COALESCE(e3.ends_at,e3.starts_at)>=NOW()) AS upcoming_at_location,
            (SELECT COUNT(*) FROM events e4 WHERE e4.group_id=g.id AND e4.status IN ('published','closed') AND COALESCE(e4.ends_at,e4.starts_at)>=NOW()) AS upcoming_for_group,
            (SELECT MAX(e5.starts_at) FROM events e5 JOIN event_locations el5 ON el5.event_id=e5.id AND el5.location_id=l.id WHERE e5.group_id=g.id AND e5.status='completed') AS last_completed_at,
            (SELECT COUNT(*) FROM event_attendance ea JOIN events ae ON ae.id=ea.event_id AND ae.group_id=g.id AND ae.status='completed' JOIN event_locations ael ON ael.event_id=ae.id AND ael.location_id=l.id WHERE ea.status IN ('checked_in','attended','left_early')) AS verified_visits,
            (SELECT COUNT(DISTINCT ea2.user_id) FROM event_attendance ea2 JOIN events ae2 ON ae2.id=ea2.event_id AND ae2.group_id=g.id AND ae2.status='completed' JOIN event_locations ael2 ON ael2.event_id=ae2.id AND ael2.location_id=l.id WHERE ea2.status IN ('checked_in','attended','left_early')) AS unique_attendees
         FROM (
            SELECT DISTINCT e.group_id,el.location_id
            FROM events e JOIN event_locations el ON el.event_id=e.id AND el.location_id IS NOT NULL
            WHERE e.status IN ('published','closed','completed')
         ) rel
         JOIN social_groups g ON g.id=rel.group_id AND g.status='active'
         JOIN locations l ON l.id=rel.location_id AND l.status='active'
         JOIN businesses b ON b.id=l.business_id AND b.status='active'
         LEFT JOIN venue_relationships vr ON vr.group_id=rel.group_id AND vr.location_id=rel.location_id
         WHERE vr.relationship_status IN ('event_venue','partner','preferred_partner','home_venue')
            OR (vr.id IS NULL AND EXISTS (
                SELECT 1 FROM events ce JOIN event_locations cel ON cel.event_id=ce.id AND cel.location_id=rel.location_id
                WHERE ce.group_id=rel.group_id AND ce.status='completed'
            ))
         ORDER BY g.name,l.name"
    )->fetchAll();

    $activePerks=coveted_event_opportunity_active_perks($pdo);$activeCampaigns=coveted_event_opportunity_active_campaigns($pdo);
    $statusWeight=['event_venue'=>4,'partner'=>9,'preferred_partner'=>14,'home_venue'=>19];
    $items=[];$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));

    foreach($rows as $row){
        $groupRef=(string)$row['group_ref'];$locationRef=(string)$row['location_ref'];$relationshipKey=(int)$row['group_id'].':'.(int)$row['location_id'];
        $upcomingForGroup=(int)$row['upcoming_for_group'];$completedEvents=(int)$row['completed_events'];$verifiedVisits=(int)$row['verified_visits'];$activeMembers=(int)$row['active_members'];$perks=(int)($activePerks[$relationshipKey]??0);$campaigns=(int)($activeCampaigns[$relationshipKey]??0);
        $relationshipStatus=trim((string)($row['stored_relationship_status']??''));if($relationshipStatus==='')$relationshipStatus=$completedEvents>0?'event_venue':'new';
        $daysSince=60;if(!empty($row['last_completed_at'])){try{$last=coveted_utc_datetime((string)$row['last_completed_at']);$daysSince=max(0,(int)floor(($now->getTimestamp()-$last->getTimestamp())/86400));}catch(Throwable){$daysSince=60;}}
        if($upcomingForGroup > 0)continue;

        $score=24;$score+=min(28,max(0,$daysSince-7));$score+=(int)($statusWeight[$relationshipStatus]??0);$score+=!empty($row['benefits_enabled'])?7:0;$score+=$perks>0?6:0;$score+=$campaigns>0?4:0;$score+=$completedEvents>=2?7:($completedEvents>0?3:0);$score+=$verifiedVisits>=20?5:($verifiedVisits>=8?3:0);$score+=$activeMembers>=12?4:0;$score=max(0,min(100,$score));
        if($score<48)continue;

        $mystery=!empty($row['mystery_events_enabled'])&&$completedEvents>=1;$eventType=$mystery?'mystery':'regular';$locationVisibility=$mystery?'scheduled_reveal':'immediate';
        $capacityBase=$completedEvents>0?(int)round($verifiedVisits/max(1,$completedEvents))+4:min(20,max(10,$activeMembers));$capacity=max(10,min(36,$capacityBase));$timezone=trim((string)($row['location_timezone']??''))?:'America/Phoenix';$start=coveted_event_opportunity_suggested_start($timezone,$daysSince>=28?10:14);$end=coveted_utc_datetime($start)->modify('+2 hours 30 minutes')->format('Y-m-d H:i:s');
        $title=$mystery?(string)$row['group_name'].' Mystery Night':(string)$row['group_name'].' at '.(string)$row['location_name'];
        $evidenceParts=[$daysSince.' days since the last completed event here',ucfirst(str_replace('_',' ',$relationshipStatus)).' relationship',$verifiedVisits.' verified visits',$activeMembers.' active group members'];
        if($perks>0)$evidenceParts[]=$perks.' active standing perk'.($perks===1?'':'s');if($campaigns>0)$evidenceParts[]=$campaigns.' active partner campaign'.($campaigns===1?'':'s');if(!empty($row['benefits_enabled']))$evidenceParts[]='relationship benefits enabled';

        $item=[
            'key'=>coveted_event_opportunity_key($groupRef,$locationRef),'score'=>$score,'priority'=>$score>=78?1:($score>=62?2:3),'category'=>'Events','title'=>'Plan '.$title,
            'detail'=>'This group has no future event scheduled and has a proven venue relationship that can support the next gathering.','evidence'=>implode(' · ',$evidenceParts).'.','href'=>'/admin/event-opportunities.php?opportunity='.rawurlencode(coveted_event_opportunity_key($groupRef,$locationRef)),
            'kind'=>'event_opportunity','execution_ready'=>true,'task_sync'=>false,
            'entity'=>['group_ref'=>$groupRef,'group_name'=>(string)$row['group_name'],'business_ref'=>(string)$row['business_ref'],'business_name'=>(string)$row['business_name'],'location_ref'=>$locationRef,'location_name'=>(string)$row['location_name'],'relationship_status'=>$relationshipStatus],
            'signals'=>['days_since_last_event'=>$daysSince,'completed_events'=>$completedEvents,'verified_visits'=>$verifiedVisits,'unique_attendees'=>(int)$row['unique_attendees'],'active_members'=>$activeMembers,'active_perks'=>$perks,'active_campaigns'=>$campaigns],
            'suggested_draft'=>['group_ref'=>$groupRef,'group_id'=>(int)$row['group_id'],'location_ref'=>$locationRef,'location_id'=>(int)$row['location_id'],'business_ref'=>(string)$row['business_ref'],'title'=>$title,'description'=>'Recommended from Coveted relationship, attendance and event-cadence signals. Review all details before publishing.','event_type'=>$eventType,'audience'=>'group','timezone'=>$timezone,'starts_at'=>$start,'ends_at'=>$end,'capacity'=>$capacity,'plus_one_allowed'=>false,'location_visibility'=>$locationVisibility,'status' => 'draft'],
        ];
        try{$item=coveted_event_learning_enrich_opportunity($admin,$item,$pdo);}catch(Throwable $e){error_log('Event Opportunity predictive enrichment unavailable: '.$e->getMessage());}
        $items[]=$item;
    }

    usort($items,static function(array $a,array $b):int{$score=((int)$b['score'])<=>((int)$a['score']);return$score!==0?$score:strcmp((string)$a['key'],(string)$b['key']);});
    return array_slice($items,0,50);
}

/** @return array<string,mixed>|null */
function coveted_event_opportunity_by_key(array $admin,string $key,?PDO $pdo=null):?array
{
    $key=trim($key);if($key===''||strlen($key)>200)return null;foreach(coveted_event_opportunities($admin,$pdo) as $item)if(hash_equals((string)$item['key'],$key))return$item;return null;
}

/** @return array<string,mixed> */
function coveted_event_opportunity_create_draft(array $admin,string $key,?PDO $pdo=null):array
{
    coveted_event_require_system_admin($admin);$pdo??=coveted_db();if(coveted_system_sample_mode($admin,$pdo))throw new InvalidArgumentException('Full System Sample Mode is read-only. Turn it off before creating an event.');
    $item=coveted_event_opportunity_by_key($admin,$key,$pdo);if(!$item||empty($item['execution_ready'])||!is_array($item['suggested_draft']??null))throw new InvalidArgumentException('That event opportunity is no longer available. Refresh the opportunity list.');
    $draft=(array)$item['suggested_draft'];$created=coveted_event_create($admin,(int)$draft['group_id'],$draft);
    try{coveted_event_set_location($admin,(string)$created['public_id'],(int)$draft['location_id']);}catch(Throwable $e){error_log('Event opportunity draft location assignment failed: '.$e->getMessage());}
    coveted_audit('event.opportunity_draft_created','event',(string)$created['public_id'],['opportunity_key'=>(string)$item['key'],'group_ref'=>(string)$draft['group_ref'],'location_ref'=>(string)$draft['location_ref'],'score'=>(int)$item['score'],'learning_confidence'=>(string)($item['predictive_plan']['confidence']??'')],(int)$admin['id']);
    return['event'=>$created,'opportunity'=>$item];
}

/** @return array<string,mixed> */
function coveted_event_opportunity_agent_context(array $admin,?PDO $pdo=null):array
{
    $items=coveted_event_opportunities($admin,$pdo);return['total'=>count($items),'high_priority'=>count(array_filter($items,static fn(array $row):bool=>(int)$row['priority']===1)),'recommendations'=>array_slice($items,0,12),'authority'=>'Recommendations are read-only. Event Learning may refine timing, capacity, Playbook and benefit evidence from completed Event Results. Event configuration and creation remain Coveted System Admin authority; Agent actions may use only the existing allowlisted canonical create_event action when autonomous mode and user intent permit it.'];
}
