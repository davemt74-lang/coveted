<?php
declare(strict_types=1);

require_once __DIR__ . '/event_results.php';

/**
 * Event Learning is a derived read layer over completed canonical event history.
 * It does not persist scores, predictions, host rankings, or learned state.
 */

function coveted_event_learning_table_available(PDO $pdo, string $table): bool
{
    if (!in_array($table, ['event_playbooks','event_proposals','event_production_items','event_production_notes'], true)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** @return array{label:string,events:int} */
function coveted_event_learning_confidence(int $events): array
{
    return [
        'label' => $events >= 5 ? 'high' : ($events >= 3 ? 'medium' : ($events >= 2 ? 'emerging' : 'low')),
        'events' => max(0, $events),
    ];
}

function coveted_event_learning_relationship_score(string $status): float
{
    return match ($status) {
        'home_venue' => 100.0,
        'preferred_partner' => 95.0,
        'partner' => 85.0,
        'event_venue' => 75.0,
        'new' => 60.0,
        default => 70.0,
    };
}

/** @param array<string,mixed> $row */
function coveted_event_learning_score_row(array $row, bool $productionAvailable): array
{
    $attending = (int)($row['attending'] ?? 0);
    $verified = (int)($row['verified'] ?? 0);
    $noShow = (int)($row['no_show'] ?? 0);
    $repeat = (int)($row['repeat_attendees'] ?? 0);
    $issued = (int)($row['rewards_issued'] ?? 0);
    $claims = (int)($row['claims'] ?? 0);
    $productionTotal = (int)($row['production_total'] ?? 0);
    $productionCompleted = (int)($row['production_completed'] ?? 0);
    $blocked = (int)($row['production_blocked'] ?? 0);
    $incidents = (int)($row['incidents'] ?? 0);

    $attendanceRate = $attending > 0 ? min(100.0, ($verified / $attending) * 100) : ($verified > 0 ? 100.0 : 60.0);
    $noShowRate = $attending > 0 ? min(100.0, ($noShow / $attending) * 100) : 0.0;
    $repeatRate = $verified > 0 ? min(100.0, ($repeat / $verified) * 100) : 0.0;
    $claimRate = $issued > 0 ? min(100.0, ($claims / $issued) * 100) : 0.0;
    $productionRate = $productionAvailable ? ($productionTotal > 0 ? min(100.0, ($productionCompleted / $productionTotal) * 100) : 100.0) : 70.0;
    $valueScore = $issued > 0 ? min(100.0, max(20.0, $claimRate * 2.0)) : 70.0;
    $relationshipScore = coveted_event_learning_relationship_score((string)($row['relationship_status'] ?? ''));

    $score = (int)round(($attendanceRate * .45) + ($productionRate * .25) + ($valueScore * .15) + ($relationshipScore * .15));
    if ($blocked > 0) $score = max(0, $score - 10);
    if ($incidents > 0) $score = max(0, $score - min(15, $incidents * 3));

    return [
        'score' => $score,
        'attendance_rate' => round($attendanceRate, 1),
        'no_show_rate' => round($noShowRate, 1),
        'repeat_rate' => round($repeatRate, 1),
        'claim_rate' => round($claimRate, 1),
        'production_completion' => round($productionRate, 1),
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_event_learning_dataset(array $admin, int $limit = 120, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) return [];

    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . max(10, min(180, $limit));
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $limit = max(10, min(180, $limit));
    $productionAvailable = coveted_event_production_schema_available($pdo);
    $proposalAvailable = coveted_event_learning_table_available($pdo, 'event_proposals') && coveted_event_learning_table_available($pdo, 'event_playbooks');

    $productionSelect = $productionAvailable ? "
        (SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=e.id AND epi.status<>'cancelled') AS production_total,
        (SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=e.id AND epi.status='completed') AS production_completed,
        (SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=e.id AND epi.status='blocked') AS production_blocked,
        (SELECT COUNT(*) FROM event_production_notes epn WHERE epn.event_id=e.id AND epn.note_type='incident') AS incidents,
        (SELECT COUNT(*) FROM event_production_notes epn WHERE epn.event_id=e.id AND epn.note_type='closeout') AS closeout_notes," : "
        0 AS production_total,0 AS production_completed,0 AS production_blocked,0 AS incidents,0 AS closeout_notes,";

    $proposalSelect = $proposalAvailable ? "
        pb.public_id AS playbook_ref,pb.playbook_key,pb.name AS playbook_name,
        pb.event_type AS playbook_event_type,pb.audience AS playbook_audience,
        pb.default_duration_minutes AS playbook_duration,pb.default_capacity AS playbook_capacity,
        pb.plus_one_allowed AS playbook_plus_one,pb.location_visibility AS playbook_location_visibility," : "
        NULL AS playbook_ref,NULL AS playbook_key,NULL AS playbook_name,
        NULL AS playbook_event_type,NULL AS playbook_audience,
        NULL AS playbook_duration,NULL AS playbook_capacity,
        NULL AS playbook_plus_one,NULL AS playbook_location_visibility,";

    $proposalJoins = $proposalAvailable ? "
        LEFT JOIN (
            SELECT converted_event_id,MAX(id) AS proposal_id
            FROM event_proposals
            WHERE status='converted' AND converted_event_id IS NOT NULL
            GROUP BY converted_event_id
        ) px ON px.converted_event_id=e.id
        LEFT JOIN event_proposals ep ON ep.id=px.proposal_id
        LEFT JOIN event_playbooks pb ON pb.id=ep.playbook_id" : "";

    $sql = "
        SELECT
            e.id,e.public_id AS event_ref,e.title,e.event_type,e.starts_at,e.ends_at,e.timezone,e.capacity,e.plus_one_allowed,e.location_visibility,
            g.id AS group_id,g.public_id AS group_ref,g.name AS group_name,
            el.location_id,l.public_id AS location_ref,l.name AS location_name,l.city AS location_city,l.region AS location_region,l.business_id,
            b.public_id AS business_ref,b.name AS business_name,
            COALESCE(vr.relationship_status,CASE WHEN l.id IS NOT NULL THEN 'event_venue' ELSE '' END) AS relationship_status,
            {$proposalSelect}
            (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id=e.id AND er.response='attending') AS attending,
            (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status IN ('checked_in','attended','left_early')) AS verified,
            (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status='no_show') AS no_show,
            (SELECT COUNT(DISTINCT cur.user_id)
               FROM event_attendance cur
              WHERE cur.event_id=e.id
                AND cur.status IN ('checked_in','attended','left_early')
                AND EXISTS (
                    SELECT 1 FROM event_attendance prior
                    JOIN events pe ON pe.id=prior.event_id
                    WHERE prior.user_id=cur.user_id
                      AND prior.status IN ('checked_in','attended','left_early')
                      AND pe.group_id=e.group_id
                      AND pe.status='completed'
                      AND pe.starts_at<e.starts_at
                )) AS repeat_attendees,
            (SELECT COUNT(*) FROM reward_issuances ri WHERE ri.event_id=e.id AND ri.status<>'cancelled') AS rewards_issued,
            (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE ri.event_id=e.id) AS claims,
            (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id JOIN campaigns c ON c.id=ri.campaign_id WHERE ri.event_id=e.id AND c.trigger_key IN ('return_visit','guest_return')) AS return_claims,
            (SELECT COUNT(*) FROM reward_claims rc JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id WHERE ri.event_id=e.id AND rc.status='refunded') AS refunds,
            {$productionSelect}
            (SELECT COUNT(*) FROM event_hosts eh WHERE eh.event_id=e.id) AS host_count
        FROM events e
        JOIN social_groups g ON g.id=e.group_id
        LEFT JOIN event_locations el ON el.id=(SELECT MIN(el2.id) FROM event_locations el2 WHERE el2.event_id=e.id)
        LEFT JOIN locations l ON l.id=el.location_id
        LEFT JOIN businesses b ON b.id=l.business_id
        LEFT JOIN venue_relationships vr ON vr.group_id=e.group_id AND vr.location_id=l.id
        {$proposalJoins}
        WHERE e.status='completed'
          AND COALESCE(e.ends_at,e.starts_at)<UTC_TIMESTAMP()
        ORDER BY e.starts_at DESC,e.id DESC
        LIMIT {$limit}";

    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        foreach (['id','group_id','location_id','business_id','capacity','attending','verified','no_show','repeat_attendees','rewards_issued','claims','return_claims','refunds','production_total','production_completed','production_blocked','incidents','closeout_notes','host_count','playbook_duration','playbook_capacity','playbook_plus_one'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int)$row[$key];
        }
        $row += coveted_event_learning_score_row($row, $productionAvailable);
        try {$zone = coveted_require_timezone(trim((string)$row['timezone']) ?: 'America/Phoenix');} catch (Throwable) {$zone = new DateTimeZone('America/Phoenix');}
        try {
            $local = coveted_utc_datetime((string)$row['starts_at'])->setTimezone($zone);
            $row['weekday'] = $local->format('l');
            $row['weekday_number'] = (int)$local->format('N');
            $row['local_hour'] = (int)$local->format('G');
            $row['local_time_label'] = $local->format('g:i A');
            $row['slot_key'] = $local->format('N') . ':' . $local->format('G');
            $row['slot_label'] = $local->format('l · g A');
        } catch (Throwable) {
            $row['weekday']='';$row['weekday_number']=4;$row['local_hour']=19;$row['local_time_label']='7:00 PM';$row['slot_key']='4:19';$row['slot_label']='Thursday · 7 PM';
        }
        $startTs=strtotime((string)$row['starts_at']);$endTs=strtotime((string)($row['ends_at'] ?: ''));
        $row['duration_minutes']=($startTs!==false && $endTs!==false && $endTs>$startTs)?(int)round(($endTs-$startTs)/60):150;
    }
    unset($row);
    return $cache[$cacheKey] = $rows;
}

/** @param array<int,array<string,mixed>> $rows */
function coveted_event_learning_best_variant(array $rows, string $field, string $labelField = ''): array
{
    $groups=[];
    foreach($rows as $row){
        $key=trim((string)($row[$field]??''));if($key==='')continue;
        if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'events'=>0,'score_sum'=>0.0,'verified'=>0,'label'=>''];
        $groups[$key]['events']++;$groups[$key]['score_sum']+=(float)($row['score']??0);$groups[$key]['verified']+=(int)($row['verified']??0);
        if($labelField!=='' && trim((string)($row[$labelField]??''))!=='')$groups[$key]['label']=(string)$row[$labelField];
    }
    foreach($groups as &$group)$group['avg_score']=round($group['score_sum']/max(1,$group['events']),1);unset($group);
    $items=array_values($groups);
    usort($items,static fn(array $a,array $b):int=>((int)$b['events']<=> (int)$a['events']) ?: ((float)$b['avg_score']<=> (float)$a['avg_score']) ?: ((int)$b['verified']<=> (int)$a['verified']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return $items[0]??[];
}

/** @param array<int,array<string,mixed>> $rows */
function coveted_event_learning_metrics(array $rows): array
{
    $events=count($rows);
    if($events===0)return ['events'=>0,'avg_score'=>0.0,'verified'=>0,'attending'=>0,'attendance_rate'=>0.0,'no_show_rate'=>0.0,'repeat_rate'=>0.0,'claim_rate'=>0.0,'production_completion'=>0.0,'incidents'=>0,'avg_verified'=>0.0,'avg_capacity'=>0.0,'recommended_capacity'=>0,'best_slot'=>[],'best_event_type'=>[],'confidence'=>coveted_event_learning_confidence(0)];
    $scoreSum=$verified=$attending=$noShow=$repeat=$issued=$claims=$prodTotal=$prodCompleted=$incidents=$capacitySum=$capacityCount=0;
    foreach($rows as $row){
        $scoreSum+=(int)$row['score'];$verified+=(int)$row['verified'];$attending+=(int)$row['attending'];$noShow+=(int)$row['no_show'];$repeat+=(int)$row['repeat_attendees'];$issued+=(int)$row['rewards_issued'];$claims+=(int)$row['claims'];$prodTotal+=(int)$row['production_total'];$prodCompleted+=(int)$row['production_completed'];$incidents+=(int)$row['incidents'];
        if((int)$row['capacity']>0){$capacitySum+=(int)$row['capacity'];$capacityCount++;}
    }
    $avgVerified=$verified/max(1,$events);$avgCapacity=$capacityCount>0?$capacitySum/$capacityCount:0.0;
    $recommendedCapacity=max(8,min(80,(int)ceil(max($avgVerified*1.15,min($avgCapacity,$avgVerified+6)))));
    return [
        'events'=>$events,'avg_score'=>round($scoreSum/$events,1),'verified'=>$verified,'attending'=>$attending,
        'attendance_rate'=>$attending>0?round(($verified/$attending)*100,1):($verified>0?100.0:0.0),'no_show_rate'=>$attending>0?round(($noShow/$attending)*100,1):0.0,
        'repeat_rate'=>$verified>0?round(($repeat/$verified)*100,1):0.0,'claim_rate'=>$issued>0?round(($claims/$issued)*100,1):0.0,
        'production_completion'=>$prodTotal>0?round(($prodCompleted/$prodTotal)*100,1):100.0,'incidents'=>$incidents,
        'avg_verified'=>round($avgVerified,1),'avg_capacity'=>round($avgCapacity,1),'recommended_capacity'=>$recommendedCapacity,
        'best_slot'=>coveted_event_learning_best_variant($rows,'slot_key','slot_label'),'best_event_type'=>coveted_event_learning_best_variant($rows,'event_type','event_type'),'confidence'=>coveted_event_learning_confidence($events),
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_event_learning_playbooks(array $rows): array
{
    $grouped=[];foreach($rows as $row){$key=trim((string)($row['playbook_key']??''));if($key!=='')$grouped[$key][]=$row;}
    $result=[];foreach($grouped as $key=>$items){$result[]=['playbook_key'=>$key,'playbook_ref'=>(string)($items[0]['playbook_ref']??''),'name'=>(string)($items[0]['playbook_name']??$key),'event_type'=>(string)($items[0]['playbook_event_type']??'')]+coveted_event_learning_metrics($items);}
    usort($result,static fn(array $a,array $b):int=>((float)$b['avg_score']<=> (float)$a['avg_score']) ?: ((int)$b['events']<=> (int)$a['events']) ?: strcmp((string)$a['playbook_key'],(string)$b['playbook_key']));return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_learning_venues(array $rows): array
{
    $grouped=[];foreach($rows as $row){$key=trim((string)($row['location_ref']??''));if($key!=='')$grouped[$key][]=$row;}
    $result=[];foreach($grouped as $ref=>$items){$result[]=['location_ref'=>$ref,'location_name'=>(string)$items[0]['location_name'],'business_ref'=>(string)$items[0]['business_ref'],'business_name'=>(string)$items[0]['business_name'],'top_group'=>coveted_event_learning_best_variant($items,'group_ref','group_name'),'top_playbook'=>coveted_event_learning_best_variant($items,'playbook_key','playbook_name')]+coveted_event_learning_metrics($items);}
    usort($result,static fn(array $a,array $b):int=>((float)$b['avg_score']<=> (float)$a['avg_score']) ?: ((int)$b['events']<=> (int)$a['events']) ?: strcmp((string)$a['location_name'],(string)$b['location_name']));return $result;
}

function coveted_event_learning_median(array $numbers): float
{
    $numbers=array_values(array_filter(array_map('intval',$numbers),static fn(int $v):bool=>$v>0));if(!$numbers)return 0.0;sort($numbers,SORT_NUMERIC);$count=count($numbers);$mid=intdiv($count,2);return $count%2===1?(float)$numbers[$mid]:(($numbers[$mid-1]+$numbers[$mid])/2);
}

/** @return array<int,array<string,mixed>> */
function coveted_event_learning_groups(array $rows): array
{
    $grouped=[];foreach($rows as $row)$grouped[(string)$row['group_ref']][]=$row;$result=[];$now=time();
    foreach($grouped as $ref=>$items){
        usort($items,static fn(array $a,array $b):int=>strcmp((string)$a['starts_at'],(string)$b['starts_at']));$metrics=coveted_event_learning_metrics($items);$gaps=[];$lastTs=null;
        foreach($items as $item){$ts=strtotime((string)$item['starts_at']);if($ts!==false&&$lastTs!==null)$gaps[]=max(1,(int)round(($ts-$lastTs)/86400));if($ts!==false)$lastTs=$ts;}
        $daysSince=$lastTs!==null?max(0,(int)floor(($now-$lastTs)/86400)):999;$events30=count(array_filter($items,static fn(array $r):bool=>(strtotime((string)$r['starts_at'])?:0)>=time()-30*86400));$recent=array_slice(array_reverse($items),0,3);$recentAvg=$recent?array_sum(array_map(static fn(array $r):int=>(int)$r['score'],$recent))/count($recent):0;$fatigue=$events30>=3?'high':(($events30>=2&&$recentAvg<70)?'watch':'low');$medianGap=coveted_event_learning_median($gaps);$idealCadence=$medianGap>0?max(14,min(75,(int)round($medianGap))):28;
        $result[]=['group_ref'=>$ref,'group_name'=>(string)$items[0]['group_name'],'days_since_last_event'=>$daysSince,'ideal_cadence_days'=>$idealCadence,'events_30d'=>$events30,'fatigue_risk'=>$fatigue,'preferred_playbook'=>coveted_event_learning_best_variant($items,'playbook_key','playbook_name'),'preferred_venue'=>coveted_event_learning_best_variant($items,'location_ref','location_name')]+$metrics;
    }
    usort($result,static fn(array $a,array $b):int=>(($a['fatigue_risk']==='high'?0:($a['fatigue_risk']==='watch'?1:2))<=>($b['fatigue_risk']==='high'?0:($b['fatigue_risk']==='watch'?1:2))) ?: ((float)$b['avg_score']<=> (float)$a['avg_score']) ?: strcmp((string)$a['group_name'],(string)$b['group_name']));return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_learning_hosts(array $rows, PDO $pdo): array
{
    if(!$rows)return[];$eventMap=[];$ids=[];foreach($rows as $row){$eventMap[(int)$row['id']]=$row;$ids[]=(int)$row['id'];}$placeholders=implode(',',array_fill(0,count($ids),'?'));$productionAvailable=coveted_event_production_schema_available($pdo);
    $taskSelect=$productionAvailable?"(SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=eh.event_id AND epi.assigned_user_id=eh.user_id AND epi.status<>'cancelled') AS assigned_tasks,(SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=eh.event_id AND epi.assigned_user_id=eh.user_id AND epi.status='completed') AS completed_tasks,(SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=eh.event_id AND epi.assigned_user_id=eh.user_id AND epi.status='blocked') AS blocked_tasks,(SELECT COUNT(*) FROM event_production_notes epn WHERE epn.event_id=eh.event_id AND epn.created_by_user_id=eh.user_id AND epn.note_type='closeout') AS closeout_notes,(SELECT COUNT(*) FROM event_production_notes epn WHERE epn.event_id=eh.event_id AND epn.created_by_user_id=eh.user_id AND epn.note_type='incident') AS incident_notes":"0 AS assigned_tasks,0 AS completed_tasks,0 AS blocked_tasks,0 AS closeout_notes,0 AS incident_notes";
    $stmt=$pdo->prepare("SELECT eh.event_id,eh.user_id,eh.host_role,u.display_name,{$taskSelect} FROM event_hosts eh JOIN users u ON u.id=eh.user_id WHERE eh.event_id IN ({$placeholders}) AND u.status='active'");$stmt->execute($ids);$grouped=[];
    foreach($stmt->fetchAll() as $row){$uid=(int)$row['user_id'];$eventId=(int)$row['event_id'];if(!isset($eventMap[$eventId]))continue;if(!isset($grouped[$uid]))$grouped[$uid]=['user_id'=>$uid,'display_name'=>(string)$row['display_name'],'events'=>0,'lead_events'=>0,'cohost_events'=>0,'checkin_events'=>0,'assigned_tasks'=>0,'completed_tasks'=>0,'blocked_tasks'=>0,'closeout_notes'=>0,'incident_notes'=>0,'score_sum'=>0];$g=&$grouped[$uid];$g['events']++;$g['score_sum']+=(int)$eventMap[$eventId]['score'];if((string)$row['host_role']==='lead')$g['lead_events']++;elseif((string)$row['host_role']==='cohost')$g['cohost_events']++;elseif((string)$row['host_role']==='checkin')$g['checkin_events']++;foreach(['assigned_tasks','completed_tasks','blocked_tasks','closeout_notes','incident_notes'] as $key)$g[$key]+=(int)$row[$key];unset($g);}
    $result=[];foreach($grouped as $g){$g['avg_event_score']=$g['events']>0?round($g['score_sum']/$g['events'],1):0.0;$g['task_completion_rate']=$g['assigned_tasks']>0?round(($g['completed_tasks']/$g['assigned_tasks'])*100,1):null;$g['confidence']=coveted_event_learning_confidence($g['events']);unset($g['score_sum']);$result[]=$g;}usort($result,static fn(array $a,array $b):int=>((int)$b['events']<=> (int)$a['events']) ?: strcmp((string)$a['display_name'],(string)$b['display_name']));return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_learning_benefits(array $eventIds, PDO $pdo, array $scoreMap = []): array
{
    $eventIds=array_values(array_unique(array_filter(array_map('intval',$eventIds),static fn(int $id):bool=>$id>0)));if(!$eventIds)return[];$placeholders=implode(',',array_fill(0,count($eventIds),'?'));
    $stmt=$pdo->prepare("SELECT ri.event_id,rt.public_id AS reward_ref,rt.title AS reward_title,rt.business_id,COALESCE(c.trigger_key,'') AS trigger_key,COUNT(DISTINCT ri.id) AS issuances,COUNT(DISTINCT rc.id) AS claims,COUNT(DISTINCT CASE WHEN rc.status='refunded' THEN rc.id END) AS refunds,COUNT(DISTINCT CASE WHEN rc.id IS NOT NULL AND c.trigger_key IN ('return_visit','guest_return') THEN rc.id END) AS return_claims FROM reward_issuances ri JOIN reward_templates rt ON rt.id=ri.reward_template_id LEFT JOIN campaigns c ON c.id=ri.campaign_id LEFT JOIN reward_claims rc ON rc.reward_issuance_id=ri.id WHERE ri.event_id IN ({$placeholders}) AND ri.status<>'cancelled' GROUP BY ri.event_id,rt.public_id,rt.title,rt.business_id,c.trigger_key");$stmt->execute($eventIds);$grouped=[];
    foreach($stmt->fetchAll() as $row){$key=(string)$row['reward_ref'].':'.(string)$row['trigger_key'];if(!isset($grouped[$key]))$grouped[$key]=['reward_ref'=>(string)$row['reward_ref'],'reward_title'=>(string)$row['reward_title'],'business_id'=>(int)$row['business_id'],'trigger_key'=>(string)$row['trigger_key'],'events'=>0,'issuances'=>0,'claims'=>0,'refunds'=>0,'return_claims'=>0,'score_sum'=>0.0];$g=&$grouped[$key];$g['events']++;$g['issuances']+=(int)$row['issuances'];$g['claims']+=(int)$row['claims'];$g['refunds']+=(int)$row['refunds'];$g['return_claims']+=(int)$row['return_claims'];$g['score_sum']+=(float)($scoreMap[(int)$row['event_id']]??0);unset($g);}
    $result=[];foreach($grouped as $g){$g['claim_rate']=$g['issuances']>0?round(($g['claims']/$g['issuances'])*100,1):0.0;$g['return_rate']=$g['issuances']>0?round(($g['return_claims']/$g['issuances'])*100,1):0.0;$g['refund_rate']=$g['claims']>0?round(($g['refunds']/$g['claims'])*100,1):0.0;$g['avg_event_score']=$g['events']>0?round($g['score_sum']/$g['events'],1):0.0;$g['confidence']=coveted_event_learning_confidence($g['events']);unset($g['score_sum']);$result[]=$g;}usort($result,static fn(array $a,array $b):int=>((float)$b['claim_rate']<=> (float)$a['claim_rate']) ?: ((int)$b['issuances']<=> (int)$a['issuances']) ?: strcmp((string)$a['reward_title'],(string)$b['reward_title']));return $result;
}

/** @return array<string,mixed> */
function coveted_event_learning_snapshot(array $admin, int $limit=120, ?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');$pdo??=coveted_db();if(coveted_system_sample_mode($admin,$pdo))return ['available'=>false,'reason'=>'sample_mode','events'=>[]];$rows=coveted_event_learning_dataset($admin,$limit,$pdo);$scoreMap=[];$ids=[];foreach($rows as $row){$scoreMap[(int)$row['id']]=(int)$row['score'];$ids[]=(int)$row['id'];}
    $playbooks=coveted_event_learning_playbooks($rows);$venues=coveted_event_learning_venues($rows);$groups=coveted_event_learning_groups($rows);$hosts=coveted_event_learning_hosts($rows,$pdo);$benefits=coveted_event_learning_benefits($ids,$pdo,$scoreMap);$recommendations=[];$add=static function(int $priority,string $key,string $title,string $detail,string $evidence='')use(&$recommendations):void{$recommendations[]=compact('priority','key','title','detail','evidence')+['category'=>'Event Learning','href'=>'/admin/event-learning.php'];};
    if(count($rows)<3){$add(3,'event-learning-sample-size','Build the Event Learning sample','Coveted needs more completed event results before it should make strong predictive claims.',count($rows).' completed event'.(count($rows)===1?'':'s').' available for learning.');}else{
        $topPlaybook=$playbooks[0]??null;if($topPlaybook&&(int)$topPlaybook['events']>=2)$add(3,'event-learning-playbook-'.(string)$topPlaybook['playbook_key'],'Use the strongest proven Playbook when it fits','Historical results show one Playbook currently outperforming the rest. Keep using Opportunity fit checks rather than applying it universally.',(string)$topPlaybook['name'].' · '.(int)$topPlaybook['events'].' events · '.(float)$topPlaybook['avg_score'].'/100 average result.');
        foreach($groups as $group){if((string)$group['fatigue_risk']==='high')$add(1,'event-learning-fatigue-'.(string)$group['group_ref'],'Give '.$group['group_name'].' more recovery time','Recent event density is high enough to create event-fatigue risk. Prefer a longer cadence before another proposal.',(int)$group['events_30d'].' completed events in the last 30 days · ideal cadence about '.(int)$group['ideal_cadence_days'].' days.');elseif((string)$group['fatigue_risk']==='watch')$add(2,'event-learning-fatigue-watch-'.(string)$group['group_ref'],'Watch '.$group['group_name'].' event cadence','Recent event density and results suggest this group may benefit from a slightly longer interval before the next gathering.',(int)$group['events_30d'].' completed events in 30 days · '.(float)$group['avg_score'].'/100 historical average.');}
        foreach($benefits as $benefit){if((int)$benefit['issuances']>=5&&(float)$benefit['claim_rate']<20){$add(2,'event-learning-benefit-'.(string)$benefit['reward_ref'],'Retune a low-converting event benefit','This reward is being issued but rarely claimed after events. Review relevance, timing and claim instructions before using it as the default value layer.',(string)$benefit['reward_title'].' · '.(int)$benefit['issuances'].' issued · '.(float)$benefit['claim_rate'].'% claim rate.');break;}}
        foreach($hosts as $host){if((int)$host['assigned_tasks']>=4&&$host['task_completion_rate']!==null&&(float)$host['task_completion_rate']<70){$add(2,'event-learning-host-process-'.(int)$host['user_id'],'Review Host Command task completion','Internal host execution data shows assigned Production work is not consistently reaching completion. Review assignments and closeout process; do not treat this as a public performance ranking.',(int)$host['assigned_tasks'].' assigned tasks · '.(float)$host['task_completion_rate'].'% completed.');break;}}
    }
    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));return ['available'=>true,'event_count'=>count($rows),'global'=>coveted_event_learning_metrics($rows),'playbooks'=>$playbooks,'venues'=>$venues,'groups'=>$groups,'hosts'=>$hosts,'benefits'=>$benefits,'recommendations'=>array_slice($recommendations,0,12),'privacy'=>'Derived operational learning only. No attendee identities, contact details, or Production note bodies are included. Host metrics are internal operational context, not a public leaderboard.','generated_at'=>gmdate('Y-m-d H:i:s')];
}

function coveted_event_learning_next_start(string $timezone,int $weekday,int $hour,int $minimumDays=12): string
{
    try{$zone=coveted_require_timezone($timezone);}catch(Throwable){$zone=new DateTimeZone('America/Phoenix');}$weekday=max(1,min(7,$weekday));$hour=max(0,min(23,$hour));$minimumDays=max(7,$minimumDays);$candidate=(new DateTimeImmutable('now',$zone))->modify('+'.$minimumDays.' days')->setTime($hour,0);while((int)$candidate->format('N')!==$weekday)$candidate=$candidate->modify('+1 day');return $candidate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** @param array<int,array<string,mixed>> $rows */
function coveted_event_learning_select_playbook(array $rows): array
{
    $variants=[];foreach($rows as $row){$key=trim((string)($row['playbook_key']??''));if($key!=='')$variants[$key][]=$row;}$ranked=[];foreach($variants as $key=>$items){$m=coveted_event_learning_metrics($items);$ranked[]=['playbook_key'=>$key,'playbook_ref'=>(string)$items[0]['playbook_ref'],'playbook_name'=>(string)$items[0]['playbook_name'],'events'=>count($items),'avg_score'=>$m['avg_score'],'row'=>$items[0]];}usort($ranked,static fn(array $a,array $b):int=>((int)$b['events']<=> (int)$a['events']) ?: ((float)$b['avg_score']<=> (float)$a['avg_score']) ?: strcmp((string)$a['playbook_key'],(string)$b['playbook_key']));return $ranked[0]??[];
}

/** @return array<string,mixed> */
function coveted_event_learning_predictive_plan(array $admin,int $groupId,int $locationId,string $timezone='America/Phoenix',?PDO $pdo=null): array
{
    $pdo??=coveted_db();if(!coveted_is_system_admin($admin)||coveted_system_sample_mode($admin,$pdo))return ['available'=>false,'confidence'=>'low'];$rows=coveted_event_learning_dataset($admin,140,$pdo);if(!$rows)return ['available'=>false,'confidence'=>'low','comparable_events'=>0];
    $exact=array_values(array_filter($rows,static fn(array $r):bool=>(int)$r['group_id']===$groupId&&(int)$r['location_id']===$locationId));$groupRows=array_values(array_filter($rows,static fn(array $r):bool=>(int)$r['group_id']===$groupId));$venueRows=array_values(array_filter($rows,static fn(array $r):bool=>(int)$r['location_id']===$locationId));if(count($exact)>=2){$comparable=$exact;$scope='group + venue';}elseif(count($groupRows)>=2){$comparable=$groupRows;$scope='group history';}elseif(count($venueRows)>=2){$comparable=$venueRows;$scope='venue history';}else{$comparable=array_slice($rows,0,20);$scope='network history';}
    $metrics=coveted_event_learning_metrics($comparable);$confidence=(string)$metrics['confidence']['label'];$slot=(array)$metrics['best_slot'];$weekday=4;$hour=19;if(!empty($slot['key'])&&str_contains((string)$slot['key'],':')){[$wd,$hr]=array_map('intval',explode(':',(string)$slot['key'],2));$weekday=max(1,min(7,$wd));$hour=max(0,min(23,$hr));}
    $playbook=coveted_event_learning_select_playbook($comparable);if(!$playbook&&$comparable!==$rows)$playbook=coveted_event_learning_select_playbook($rows);$playbookRow=(array)($playbook['row']??[]);$duration=(int)($playbookRow['playbook_duration']??0);if($duration<30)$duration=max(60,min(360,(int)round(array_sum(array_map(static fn(array $r):int=>(int)$r['duration_minutes'],$comparable))/max(1,count($comparable)))));$start=coveted_event_learning_next_start($timezone,$weekday,$hour,14);$end=coveted_utc_datetime($start)->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s');
    $score=(float)$metrics['avg_score'];$adjustment=$score>=85?10:($score>=75?6:($score>=65?2:($score<55?-8:($score<65?-3:0))));if($confidence==='low')$adjustment=(int)round($adjustment*.25);elseif($confidence==='emerging')$adjustment=(int)round($adjustment*.6);
    $eventIds=array_map(static fn(array $r):int=>(int)$r['id'],$comparable);$scoreMap=[];foreach($comparable as $r)$scoreMap[(int)$r['id']]=(int)$r['score'];$benefits=coveted_event_learning_benefits($eventIds,$pdo,$scoreMap);$bestBenefit=null;foreach($benefits as $benefit){if((int)$benefit['issuances']>=3&&(float)$benefit['claim_rate']>=30){$bestBenefit=$benefit;break;}}
    $evidence=[count($comparable).' comparable completed event'.(count($comparable)===1?'':'s').' from '.$scope,(float)$metrics['avg_score'].'/100 average Event Result',(float)$metrics['attendance_rate'].'% RSVP-to-attendance'];if(!empty($slot['label']))$evidence[]='best observed slot '.(string)$slot['label'];if($playbook)$evidence[]=(string)$playbook['playbook_name'].' Playbook · '.(int)$playbook['events'].' comparable event'.((int)$playbook['events']===1?'':'s');if($bestBenefit)$evidence[]=(string)$bestBenefit['reward_title'].' · '.(float)$bestBenefit['claim_rate'].'% claim rate';
    return ['available'=>true,'confidence'=>$confidence,'comparable_events'=>count($comparable),'source_scope'=>$scope,'avg_result_score'=>(float)$metrics['avg_score'],'attendance_rate'=>(float)$metrics['attendance_rate'],'no_show_rate'=>(float)$metrics['no_show_rate'],'repeat_rate'=>(float)$metrics['repeat_rate'],'score_adjustment'=>$adjustment,'recommended_capacity'=>(int)$metrics['recommended_capacity'],'weekday'=>$weekday,'hour'=>$hour,'slot_label'=>(string)($slot['label']??''),'starts_at'=>$start,'ends_at'=>$end,'duration_minutes'=>$duration,'playbook_key'=>(string)($playbook['playbook_key']??''),'playbook_ref'=>(string)($playbook['playbook_ref']??''),'playbook_name'=>(string)($playbook['playbook_name']??''),'playbook_events'=>(int)($playbook['events']??0),'playbook_event_type'=>(string)($playbookRow['playbook_event_type']??''),'playbook_audience'=>(string)($playbookRow['playbook_audience']??''),'playbook_plus_one'=>(bool)($playbookRow['playbook_plus_one']??false),'playbook_location_visibility'=>(string)($playbookRow['playbook_location_visibility']??''),'benefit'=>(array)($bestBenefit??[]),'evidence'=>$evidence];
}

/** @return array<string,mixed> */
function coveted_event_learning_enrich_opportunity(array $admin,array $opportunity,?PDO $pdo=null): array
{
    $pdo??=coveted_db();$draft=(array)($opportunity['suggested_draft']??[]);$groupId=(int)($draft['group_id']??0);$locationId=(int)($draft['location_id']??0);if($groupId<1||$locationId<1)return $opportunity;$plan=coveted_event_learning_predictive_plan($admin,$groupId,$locationId,(string)($draft['timezone']??'America/Phoenix'),$pdo);if(empty($plan['available']))return $opportunity;
    $opportunity['predictive_plan']=$plan;$opportunity['signals']['learning_confidence']=(string)$plan['confidence'];$opportunity['signals']['learning_comparable_events']=(int)$plan['comparable_events'];$opportunity['signals']['historical_result_score']=(float)$plan['avg_result_score'];$opportunity['score']=max(0,min(100,(int)$opportunity['score']+(int)$plan['score_adjustment']));$opportunity['priority']=$opportunity['score']>=78?1:($opportunity['score']>=62?2:3);
    if(in_array((string)$plan['confidence'],['emerging','medium','high'],true)){$draft['starts_at']=(string)$plan['starts_at'];$draft['ends_at']=(string)$plan['ends_at'];$activeMembers=(int)($opportunity['signals']['active_members']??0);$capacity=(int)$plan['recommended_capacity'];if($activeMembers>0)$capacity=min($capacity,max(10,$activeMembers+4));$draft['capacity']=max(8,$capacity);if((int)$plan['playbook_events']>=2){if((string)$plan['playbook_event_type']!=='')$draft['event_type']=(string)$plan['playbook_event_type'];if((string)$plan['playbook_audience']!=='')$draft['audience']=(string)$plan['playbook_audience'];$draft['plus_one_allowed']=!empty($plan['playbook_plus_one']);if((string)$plan['playbook_location_visibility']!=='')$draft['location_visibility']=(string)$plan['playbook_location_visibility'];}}
    $draft['description']=rtrim((string)($draft['description']??'')).' Predictive planning uses completed Event Results when confidence is sufficient; all details remain System Admin reviewable.';$opportunity['suggested_draft']=$draft;$opportunity['evidence']=rtrim((string)($opportunity['evidence']??'')).' Learning: '.implode(' · ',array_slice((array)$plan['evidence'],0,5)).'.';$opportunity['detail']='Coveted found a proven partner opportunity and refined the draft using completed Event Results. Review the predictive evidence before creating a Proposal.';return $opportunity;
}

/** @return array<string,mixed> */
function coveted_event_learning_agent_context(array $admin,int $limit=120,?PDO $pdo=null): array
{
    $pdo??=coveted_db();if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');if(coveted_system_sample_mode($admin,$pdo))return ['available'=>false,'reason'=>'sample_mode','recommendations'=>[],'attention'=>0];$snapshot=coveted_event_learning_snapshot($admin,$limit,$pdo);
    $groups=array_slice(array_map(static fn(array $g):array=>['group_ref'=>(string)$g['group_ref'],'group_name'=>(string)$g['group_name'],'events'=>(int)$g['events'],'avg_score'=>(float)$g['avg_score'],'attendance_rate'=>(float)$g['attendance_rate'],'repeat_rate'=>(float)$g['repeat_rate'],'ideal_cadence_days'=>(int)$g['ideal_cadence_days'],'fatigue_risk'=>(string)$g['fatigue_risk'],'recommended_capacity'=>(int)$g['recommended_capacity'],'best_slot'=>(string)($g['best_slot']['label']??''),'preferred_playbook'=>(string)($g['preferred_playbook']['label']??'')],(array)$snapshot['groups']),0,12);
    $playbooks=array_slice(array_map(static fn(array $p):array=>['playbook_key'=>(string)$p['playbook_key'],'name'=>(string)$p['name'],'events'=>(int)$p['events'],'avg_score'=>(float)$p['avg_score'],'attendance_rate'=>(float)$p['attendance_rate'],'repeat_rate'=>(float)$p['repeat_rate'],'claim_rate'=>(float)$p['claim_rate'],'recommended_capacity'=>(int)$p['recommended_capacity']],(array)$snapshot['playbooks']),0,10);
    $venues=array_slice(array_map(static fn(array $v):array=>['location_ref'=>(string)$v['location_ref'],'location_name'=>(string)$v['location_name'],'business_name'=>(string)$v['business_name'],'events'=>(int)$v['events'],'avg_score'=>(float)$v['avg_score'],'attendance_rate'=>(float)$v['attendance_rate'],'return_signal'=>(float)$v['repeat_rate'],'best_slot'=>(string)($v['best_slot']['label']??'')],(array)$snapshot['venues']),0,10);
    $benefits=array_slice(array_map(static fn(array $b):array=>['reward_ref'=>(string)$b['reward_ref'],'reward_title'=>(string)$b['reward_title'],'events'=>(int)$b['events'],'issuances'=>(int)$b['issuances'],'claim_rate'=>(float)$b['claim_rate'],'return_rate'=>(float)$b['return_rate'],'refund_rate'=>(float)$b['refund_rate']],(array)$snapshot['benefits']),0,10);
    $hostOps=array_slice(array_map(static fn(array $h):array=>['display_name'=>(string)$h['display_name'],'events'=>(int)$h['events'],'lead_events'=>(int)$h['lead_events'],'assigned_tasks'=>(int)$h['assigned_tasks'],'task_completion_rate'=>$h['task_completion_rate'],'blocked_tasks'=>(int)$h['blocked_tasks'],'avg_event_score'=>(float)$h['avg_event_score']],(array)$snapshot['hosts']),0,10);
    $attention=count(array_filter((array)$snapshot['recommendations'],static fn(array $r):bool=>(int)$r['priority']===1));return ['available'=>true,'event_count'=>(int)$snapshot['event_count'],'global'=>$snapshot['global'],'playbooks'=>$playbooks,'venues'=>$venues,'groups'=>$groups,'benefits'=>$benefits,'host_operations'=>$hostOps,'recommendations'=>array_slice((array)$snapshot['recommendations'],0,12),'attention'=>$attention,'authority'=>'Event Learning is read-only predictive evidence. It can refine Event Opportunities and recommend Playbooks, timing, capacity and benefits, but only System Admin can approve Proposals or create/configure Events.','privacy'=>(string)$snapshot['privacy']];
}
