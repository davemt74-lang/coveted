<?php
declare(strict_types=1);

require_once __DIR__ . '/event_management.php';
require_once __DIR__ . '/event_production.php';
require_once __DIR__ . '/venue_relationships.php';
require_once __DIR__ . '/partner_crm.php';
require_once __DIR__ . '/system_sample_data.php';

/**
 * Event Results is a derived read layer over canonical Coveted records.
 * No event-result rows are stored. Attendance, rewards, claims, Production,
 * host work and Partner CRM remain the sources of truth.
 */

function coveted_event_results_require_admin(array $actor, ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($actor, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode is read-only. Event Results is available only for live canonical events.');
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_event_results_recent(array $admin, int $limit = 40, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_event_results_require_admin($admin, $pdo);
    $limit = max(1, min(100, $limit));
    return $pdo->query(
        "SELECT e.public_id,e.title,e.status,e.event_type,e.starts_at,e.ends_at,e.timezone,
                g.public_id AS group_ref,g.name AS group_name,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id=e.id AND er.response='attending') AS attending,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status IN ('checked_in','attended','left_early')) AS verified,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status='no_show') AS no_show,
                (SELECT COUNT(*) FROM reward_issuances ri WHERE ri.event_id=e.id AND ri.status<>'cancelled') AS rewards_issued,
                (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE ri.event_id=e.id) AS claims
         FROM events e
         JOIN social_groups g ON g.id=e.group_id
         WHERE e.status IN ('completed','closed')
           AND COALESCE(e.ends_at,e.starts_at) < UTC_TIMESTAMP()
         ORDER BY e.starts_at DESC,e.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}

/** @return array<string,mixed> */
function coveted_event_results_event(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_event_results_require_admin($admin, $pdo);
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) throw new InvalidArgumentException('Event not found.');

    $stmt = $pdo->prepare(
        "SELECT e.*,g.public_id AS group_ref,g.name AS group_name
         FROM events e JOIN social_groups g ON g.id=e.group_id
         WHERE (e.public_id=? OR CAST(e.id AS CHAR)=?) LIMIT 1"
    );
    $stmt->execute([$eventRef,$eventRef]);
    $event = $stmt->fetch();
    if (!$event) throw new InvalidArgumentException('Event not found.');
    if (!in_array((string)$event['status'], ['completed','closed'], true)) {
        throw new InvalidArgumentException('Event Results is available after an event is closed or completed.');
    }
    $end = (string)($event['ends_at'] ?: $event['starts_at']);
    if (strtotime($end) !== false && strtotime($end) > time()) {
        throw new InvalidArgumentException('Event Results is available after the event has ended.');
    }
    return $event;
}

/** @return array<string,mixed>|null */
function coveted_event_results_location(array $event, PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT el.location_id,el.private_location_label,el.reveal_notes,
                l.public_id AS location_ref,l.name AS location_name,l.city,l.region,l.business_id,
                b.public_id AS business_ref,b.name AS business_name
         FROM event_locations el
         LEFT JOIN locations l ON l.id=el.location_id
         LEFT JOIN businesses b ON b.id=l.business_id
         WHERE el.event_id=?
         ORDER BY el.id ASC LIMIT 1"
    );
    $stmt->execute([(int)$event['id']]);
    return $stmt->fetch() ?: null;
}

/** @return array<string,int> */
function coveted_event_results_people_metrics(array $event, PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM event_invitations WHERE event_id=e.id AND status<>'revoked') AS invited,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='attending') AS attending,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='waitlist') AS waitlist,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='declined') AS declined,
            (SELECT COUNT(*) FROM event_attendance WHERE event_id=e.id AND status='checked_in') AS checked_in,
            (SELECT COUNT(*) FROM event_attendance WHERE event_id=e.id AND status='attended') AS attended,
            (SELECT COUNT(*) FROM event_attendance WHERE event_id=e.id AND status='left_early') AS left_early,
            (SELECT COUNT(*) FROM event_attendance WHERE event_id=e.id AND status='no_show') AS no_show,
            (SELECT COUNT(DISTINCT user_id) FROM event_attendance WHERE event_id=e.id AND status IN ('checked_in','attended','left_early')) AS verified
         FROM events e WHERE e.id=? LIMIT 1"
    );
    $stmt->execute([(int)$event['id']]);
    $row = $stmt->fetch() ?: [];
    foreach ($row as $key=>$value) $row[$key]=(int)$value;

    $repeat = $pdo->prepare(
        "SELECT COUNT(DISTINCT current.user_id)
         FROM event_attendance current
         WHERE current.event_id=?
           AND current.status IN ('checked_in','attended','left_early')
           AND EXISTS (
               SELECT 1 FROM event_attendance prior
               JOIN events pe ON pe.id=prior.event_id
               WHERE prior.user_id=current.user_id
                 AND prior.status IN ('checked_in','attended','left_early')
                 AND pe.group_id=?
                 AND pe.status='completed'
                 AND pe.starts_at < ?
           )"
    );
    $repeat->execute([(int)$event['id'],(int)$event['group_id'],(string)$event['starts_at']]);
    $row['repeat_attendees']=(int)$repeat->fetchColumn();
    $row['first_time_attendees']=max(0,(int)$row['verified']-(int)$row['repeat_attendees']);
    return $row;
}

/** @return array<string,int|float> */
function coveted_event_results_value_metrics(array $event, PDO $pdo): array
{
    $stmt=$pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM campaign_event_links WHERE event_id=e.id) AS linked_campaigns,
            (SELECT COUNT(*) FROM reward_issuances WHERE event_id=e.id AND status<>'cancelled') AS rewards_issued,
            (SELECT COUNT(DISTINCT user_id) FROM reward_issuances WHERE event_id=e.id AND status<>'cancelled') AS members_reached,
            (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE ri.event_id=e.id) AS claims,
            (SELECT COUNT(DISTINCT ri.user_id) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE ri.event_id=e.id) AS claiming_members,
            (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE ri.event_id=e.id AND rc.status='refunded') AS refunds,
            (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id JOIN campaigns c ON c.id=ri.campaign_id WHERE ri.event_id=e.id AND c.trigger_key IN ('return_visit','guest_return')) AS return_claims
         FROM events e WHERE e.id=? LIMIT 1"
    );
    $stmt->execute([(int)$event['id']]);
    $row=$stmt->fetch() ?: [];
    foreach ($row as $key=>$value) $row[$key]=(int)$value;
    $row['claim_rate']=(int)$row['rewards_issued']>0 ? round(((int)$row['claims']/(int)$row['rewards_issued'])*100,1) : 0.0;
    return $row;
}

/** @return array<string,mixed> */
function coveted_event_results_production_metrics(array $event, PDO $pdo): array
{
    $result=['available'=>false,'total'=>0,'completed'=>0,'open'=>0,'blocked'=>0,'cancelled'=>0,'completion_rate'=>0.0,'incidents'=>0,'closeout_notes'=>0];
    if (!coveted_event_production_schema_available($pdo)) return $result;
    $result['available']=true;
    $stmt=$pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status='completed') AS completed,
                SUM(status IN ('open','in_progress')) AS open_count,
                SUM(status='blocked') AS blocked,
                SUM(status='cancelled') AS cancelled
         FROM event_production_items WHERE event_id=?"
    );
    $stmt->execute([(int)$event['id']]);
    $row=$stmt->fetch() ?: [];
    $active=max(0,(int)($row['total']??0)-(int)($row['cancelled']??0));
    $result['total']=$active;
    $result['completed']=(int)($row['completed']??0);
    $result['open']=(int)($row['open_count']??0);
    $result['blocked']=(int)($row['blocked']??0);
    $result['cancelled']=(int)($row['cancelled']??0);
    $result['completion_rate']=$active>0?round(($result['completed']/$active)*100,1):100.0;

    $notes=$pdo->prepare(
        "SELECT SUM(note_type='incident') AS incidents,SUM(note_type='closeout') AS closeout_notes
         FROM event_production_notes WHERE event_id=?"
    );
    $notes->execute([(int)$event['id']]);
    $noteRow=$notes->fetch() ?: [];
    $result['incidents']=(int)($noteRow['incidents']??0);
    $result['closeout_notes']=(int)($noteRow['closeout_notes']??0);
    return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_results_host_metrics(array $event, PDO $pdo): array
{
    $productionAvailable=coveted_event_production_schema_available($pdo);
    $sql="SELECT eh.user_id,eh.host_role,u.display_name,
                 ".($productionAvailable
                    ? "(SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=eh.event_id AND epi.assigned_user_id=eh.user_id AND epi.status<>'cancelled')"
                    : "0")." AS assigned_tasks,
                 ".($productionAvailable
                    ? "(SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=eh.event_id AND epi.assigned_user_id=eh.user_id AND epi.status='completed')"
                    : "0")." AS completed_tasks,
                 ".($productionAvailable
                    ? "(SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=eh.event_id AND epi.assigned_user_id=eh.user_id AND epi.status='blocked')"
                    : "0")." AS blocked_tasks,
                 ".($productionAvailable
                    ? "(SELECT COUNT(*) FROM event_production_notes epn WHERE epn.event_id=eh.event_id AND epn.created_by_user_id=eh.user_id AND epn.note_type IN ('incident','closeout'))"
                    : "0")." AS operational_notes
          FROM event_hosts eh JOIN users u ON u.id=eh.user_id
          WHERE eh.event_id=? ORDER BY FIELD(eh.host_role,'lead','cohost','checkin'),u.display_name,u.id";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([(int)$event['id']]);
    $rows=$stmt->fetchAll();
    foreach($rows as &$row){
        foreach(['user_id','assigned_tasks','completed_tasks','blocked_tasks','operational_notes'] as $key) $row[$key]=(int)$row[$key];
        $row['task_completion_rate']=$row['assigned_tasks']>0?round(($row['completed_tasks']/$row['assigned_tasks'])*100,1):null;
    }
    unset($row);
    return $rows;
}

/** @return array<string,mixed> */
function coveted_event_results_partner_metrics(array $admin,array $event,?array $location,PDO $pdo): array
{
    $empty=['available'=>false,'business_id'=>null,'business_name'=>'','location_ref'=>'','location_name'=>'','relationship_status'=>'','completed_events'=>0,'verified_visits'=>0,'repeat_attendees'=>0,'claims'=>0,'return_claims'=>0,'open_followups'=>0,'overdue_followups'=>0];
    if (!$location || (int)($location['business_id']??0)<1 || (int)($location['location_id']??0)<1) return $empty;
    $businessId=(int)$location['business_id'];
    $match=null;
    try {
        foreach(coveted_venue_relationships_for_business($admin,$businessId) as $rel){
            if((int)$rel['group_id']===(int)$event['group_id'] && (int)$rel['location_id']===(int)$location['location_id']){$match=$rel;break;}
        }
    } catch(Throwable) {}
    $result=$empty;
    $result['available']=true;
    $result['business_id']=$businessId;
    $result['business_name']=(string)($location['business_name']??'');
    $result['location_ref']=(string)($location['location_ref']??'');
    $result['location_name']=(string)($location['location_name']??'');
    if($match){
        foreach(['relationship_status','completed_events','verified_visits','repeat_attendees','claims','return_claims'] as $key) $result[$key]=$match[$key]??$result[$key];
        foreach(['completed_events','verified_visits','repeat_attendees','claims','return_claims'] as $key) $result[$key]=(int)$result[$key];
    }
    if(coveted_partner_crm_schema_available($pdo)){
        try{
            $followups=coveted_partner_followups($admin,$businessId,(string)$event['group_ref'],(string)$location['location_ref'],80);
            foreach($followups as $followup){
                if((string)$followup['status']!=='open') continue;
                $result['open_followups']++;
                if(strtotime((string)$followup['due_at'])!==false && strtotime((string)$followup['due_at'])<time()) $result['overdue_followups']++;
            }
        }catch(Throwable){}
    }
    return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_results_recommendations(array $event,array $people,array $value,array $production,array $partner,int $score): array
{
    $items=[];
    $add=static function(int $priority,string $key,string $title,string $detail,string $href,string $evidence='')use(&$items):void{
        $items[]=compact('priority','key','title','detail','href','evidence')+['category'=>'Post-event'];
    };
    $eventHref='/admin/event-results.php?event='.rawurlencode((string)$event['public_id']);
    if((string)$event['status']==='closed'){
        $add(1,'event-result-finalize-'.(string)$event['public_id'],'Finalize the event lifecycle','The gathering has ended but is still closed rather than completed. Finish canonical lifecycle closeout before treating the result as final.','/admin/event.php?event='.rawurlencode((string)$event['public_id']),'Status is closed after the event end.');
    }
    $attendanceRate=(int)$people['attending']>0?round(((int)$people['verified']/(int)$people['attending'])*100,1):100.0;
    $noShowRate=(int)$people['attending']>0?round(((int)$people['no_show']/(int)$people['attending'])*100,1):0.0;
    if((int)$people['attending']>0 && $attendanceRate<75){
        $add(1,'event-result-attendance-'.(string)$event['public_id'],'Review attendance conversion','Verified attendance came in well below the RSVP count. Review reminder timing, invitation quality and arrival friction before repeating the format.',$eventHref,$attendanceRate.'% RSVP-to-verified attendance.');
    }
    if((int)$people['no_show']>0 && $noShowRate>=20){
        $add(1,'event-result-noshow-'.(string)$event['public_id'],'Reduce no-show risk next time','A meaningful share of attending RSVPs were recorded as no-shows. Tighten confirmation and waitlist backfill for the next event.',$eventHref,$noShowRate.'% no-show rate.');
    }
    if(!empty($production['available']) && ((int)$production['open']>0 || (int)$production['blocked']>0)){
        $add(1,'event-result-production-'.(string)$event['public_id'],'Finish Production closeout','The event has ended with Production work still open or blocked. Resolve it before the operational record is considered complete.','/admin/event-production.php?event='.rawurlencode((string)$event['public_id']),(int)$production['open'].' open · '.(int)$production['blocked'].' blocked.');
    }
    if(!empty($production['available']) && (int)$production['closeout_notes']===0){
        $add(2,'event-result-closeout-note-'.(string)$event['public_id'],'Capture the host closeout','No closeout note is recorded. Add the venue, guest-flow and host lessons while they are still fresh.','/admin/event-production.php?event='.rawurlencode((string)$event['public_id']),'No Production closeout note.');
    }
    if(!empty($production['available']) && (int)$production['incidents']>0){
        $add(1,'event-result-incidents-'.(string)$event['public_id'],'Review event incidents','Operational incidents were logged. Review them before repeating the same run of show or venue plan.',$eventHref,(int)$production['incidents'].' incident note'.((int)$production['incidents']===1?'':'s').' recorded.');
    }
    if((int)$value['rewards_issued']>0 && (float)$value['claim_rate']<25){
        $add(2,'event-result-value-'.(string)$event['public_id'],'Improve post-event value conversion','Rewards were issued but claim activity is weak. Revisit reward relevance, claim instructions and return-visit timing.','/admin/?view=benefits',(float)$value['claim_rate'].'% claim rate from '.(int)$value['rewards_issued'].' issuances.');
    }
    if(!empty($partner['available']) && (int)$partner['open_followups']===0){
        $add(2,'event-result-partner-followup-'.(string)$event['public_id'],'Create the partner follow-up','This partner event has no open relationship follow-up. Capture venue feedback and agree on the next action.',$eventHref,'No open Partner CRM follow-up for this relationship.');
    }
    if(!empty($partner['available']) && (int)$partner['overdue_followups']>0){
        $add(1,'event-result-partner-overdue-'.(string)$event['public_id'],'Close the overdue partner follow-up','The relationship already has an overdue follow-up. Resolve that commitment before starting another negotiation.',$eventHref,(int)$partner['overdue_followups'].' overdue Partner CRM follow-up'.((int)$partner['overdue_followups']===1?'':'s').'.');
    }
    $repeatRate=(int)$people['verified']>0?round(((int)$people['repeat_attendees']/(int)$people['verified'])*100,1):0.0;
    if($score>=80 && (int)$people['verified']>=4){
        $add(3,'event-result-next-'.(string)$event['public_id'],'Feed this result into the next Event Opportunity','This event produced a strong enough result to consider repeating or evolving the format. Use the Opportunity Engine so timing, partner and group cadence are checked before creating anything.','/admin/event-opportunities.php','Result score '.$score.'/100 · '.$repeatRate.'% repeat attendance.');
    }
    if(!empty($partner['available']) && (string)$partner['relationship_status']==='event_venue' && (int)$partner['completed_events']>=2 && $score>=75){
        $add(2,'event-result-relationship-'.(string)$event['public_id'],'Review partner relationship status','Repeated successful events may justify moving this venue beyond Event Venue status. Review the relationship before the next proposal.','/venue-relationships.php',''.(int)$partner['completed_events'].' completed events · result score '.$score.'/100.');
    }
    usort($items,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return $items;
}

/** @return array<string,mixed> */
function coveted_event_results_snapshot(array $admin,string $eventRef,?PDO $pdo=null): array
{
    $pdo ??= coveted_db();
    $event=coveted_event_results_event($admin,$eventRef,$pdo);
    $location=coveted_event_results_location($event,$pdo);
    $people=coveted_event_results_people_metrics($event,$pdo);
    $value=coveted_event_results_value_metrics($event,$pdo);
    $production=coveted_event_results_production_metrics($event,$pdo);
    $hosts=coveted_event_results_host_metrics($event,$pdo);
    $partner=coveted_event_results_partner_metrics($admin,$event,$location,$pdo);

    $attendanceRate=(int)$people['attending']>0?min(100.0,((int)$people['verified']/(int)$people['attending'])*100):((int)$people['verified']>0?100.0:60.0);
    $productionScore=!empty($production['available'])?(float)$production['completion_rate']:70.0;
    $valueScore=(int)$value['rewards_issued']>0?min(100.0,max(20.0,(float)$value['claim_rate']*2.0)):70.0;
    $relationshipScore=match((string)($partner['relationship_status']??'')){'home_venue'=>100.0,'preferred_partner'=>95.0,'partner'=>85.0,'event_venue'=>75.0,'new'=>60.0,default=>70.0};
    $score=(int)round(($attendanceRate*.45)+($productionScore*.25)+($valueScore*.15)+($relationshipScore*.15));
    if((int)$production['blocked']>0) $score=max(0,$score-10);
    if((int)$production['incidents']>0) $score=max(0,$score-min(15,(int)$production['incidents']*3));
    $outcome=$score>=85?'strong':($score>=70?'healthy':($score>=55?'watch':'needs_attention'));
    $recommendations=coveted_event_results_recommendations($event,$people,$value,$production,$partner,$score);

    return [
        'event'=>$event,'location'=>$location,'people'=>$people,'value'=>$value,'production'=>$production,'hosts'=>$hosts,'partner'=>$partner,
        'score'=>$score,'outcome'=>$outcome,'recommendations'=>$recommendations,
        'privacy'=>'Event Results Agent context uses aggregate operational metrics only; attendee identities and Production note bodies are excluded.',
    ];
}

function coveted_event_results_create_partner_followup(array $admin,string $eventRef,?PDO $pdo=null): string
{
    $pdo ??= coveted_db();
    $snapshot=coveted_event_results_snapshot($admin,$eventRef,$pdo);
    $event=(array)$snapshot['event'];$location=(array)($snapshot['location']??[]);$partner=(array)$snapshot['partner'];
    if(empty($partner['available']) || (int)($partner['business_id']??0)<1 || trim((string)($location['location_ref']??''))==='') {
        throw new InvalidArgumentException('This event does not have a canonical partner location.');
    }
    if(!coveted_partner_crm_schema_available($pdo)) throw new RuntimeException('Partner Profile CRM migration is not installed.');
    if((int)($partner['open_followups']??0)>0) throw new InvalidArgumentException('This partner relationship already has an open follow-up.');
    $due=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d H:i:s');
    $people=(array)$snapshot['people'];$value=(array)$snapshot['value'];
    $detail='Post-event review for '.(string)$event['title'].'. Result score '.(int)$snapshot['score'].'/100; '.(int)$people['verified'].' verified attendance from '.(int)$people['attending'].' attending RSVPs; '.(int)$value['claims'].' claims from '.(int)$value['rewards_issued'].' reward issuances. Capture partner feedback and agree on the next action.';
    return coveted_partner_followup_add($admin,(int)$partner['business_id'],(string)$event['group_ref'],(string)$location['location_ref'],[
        'title'=>'Post-event follow-up · '.mb_substr((string)$event['title'],0,150),
        'detail'=>$detail,
        'due_at'=>$due,
        'priority'=>(int)$snapshot['score']<70?'high':'normal',
        'assigned_user_id'=>(int)$admin['id'],
    ]);
}

/** @return array<string,mixed> */
function coveted_event_results_agent_context(array $admin,int $limit=10,?PDO $pdo=null): array
{
    $pdo ??= coveted_db();
    if(!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if(coveted_system_sample_mode($admin,$pdo)) return ['available'=>false,'reason'=>'sample_mode','events'=>[],'recommendations'=>[],'attention'=>0];
    $limit=max(1,min(16,$limit));
    $recent=coveted_event_results_recent($admin,$limit,$pdo);
    $events=[];$recommendations=[];$attention=0;
    foreach($recent as $row){
        try{$snapshot=coveted_event_results_snapshot($admin,(string)$row['public_id'],$pdo);}catch(Throwable){continue;}
        $event=(array)$snapshot['event'];$people=(array)$snapshot['people'];$value=(array)$snapshot['value'];$production=(array)$snapshot['production'];$partner=(array)$snapshot['partner'];
        $eventRecommendations=array_slice((array)$snapshot['recommendations'],0,4);
        if((int)$snapshot['score']<70 || array_filter($eventRecommendations,static fn(array $r):bool=>(int)$r['priority']===1)) $attention++;
        $events[]=[
            'event_ref'=>(string)$event['public_id'],'title'=>(string)$event['title'],'group'=>(string)$event['group_name'],'status'=>(string)$event['status'],'starts_at'=>(string)$event['starts_at'],
            'score'=>(int)$snapshot['score'],'outcome'=>(string)$snapshot['outcome'],'attending'=>(int)$people['attending'],'verified'=>(int)$people['verified'],'no_show'=>(int)$people['no_show'],'repeat_attendees'=>(int)$people['repeat_attendees'],
            'rewards_issued'=>(int)$value['rewards_issued'],'claims'=>(int)$value['claims'],'claim_rate'=>(float)$value['claim_rate'],'production_completion'=>(float)$production['completion_rate'],'production_blocked'=>(int)$production['blocked'],'incidents'=>(int)$production['incidents'],
            'partner_status'=>(string)($partner['relationship_status']??''),'partner_open_followups'=>(int)($partner['open_followups']??0),'href'=>'/admin/event-results.php?event='.rawurlencode((string)$event['public_id']),
        ];
        foreach($eventRecommendations as $rec){
            if(count($recommendations)>=12) break;
            $recommendations[]=$rec;
        }
    }
    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return ['available'=>true,'events'=>$events,'recommendations'=>array_slice($recommendations,0,12),'attention'=>$attention,'privacy'=>'Aggregate event-result metrics only. No attendee identities or note bodies are included.'];
}
