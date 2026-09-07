<?php
declare(strict_types=1);

require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/system_sample_data.php';
require_once __DIR__ . '/event_guest_mix.php';
require_once __DIR__ . '/member_journey.php';
require_once __DIR__ . '/member_journey_scan.php';

/**
 * Member Relationship Intelligence is a read-only view over canonical group,
 * event and verified-attendance state. It does not persist a social graph and
 * deliberately does not read Mutual Reconnect choices.
 */
function coveted_member_relationship_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Member Relationship Intelligence.');
    }
    return $pdo;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_groups(array $admin, ?PDO $pdo = null): array
{
    $pdo = coveted_member_relationship_require_admin($admin, $pdo);
    return $pdo->query(
        "SELECT g.id,g.public_id,g.name,g.city,
                (SELECT COUNT(*) FROM group_memberships gm JOIN users u ON u.id=gm.user_id
                 WHERE gm.group_id=g.id AND gm.membership_status='active' AND gm.group_role<>'guest' AND u.status='active') AS active_members,
                (SELECT COUNT(*) FROM events e WHERE e.group_id=g.id AND e.status='completed' AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) AS events_90d,
                (SELECT COUNT(*) FROM events e WHERE e.group_id=g.id AND e.status='completed' AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) AND e.starts_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) AS events_prior_90d,
                (SELECT MAX(e.starts_at) FROM events e WHERE e.group_id=g.id AND e.status='completed') AS last_completed_at
         FROM social_groups g
         WHERE g.status='active'
         ORDER BY g.name,g.id"
    )->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_active_members(PDO $pdo, int $groupId): array
{
    $stmt=$pdo->prepare(
        "SELECT gm.user_id,gm.group_role,gm.joined_at,u.display_name
         FROM group_memberships gm
         JOIN users u ON u.id=gm.user_id
         WHERE gm.group_id=? AND gm.membership_status='active' AND gm.group_role<>'guest' AND u.status='active'
         ORDER BY u.display_name,u.id"
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_member_relationship_attendance(PDO $pdo, int $groupId): array
{
    $stmt=$pdo->prepare(
        "SELECT e.id AS event_id,e.public_id AS event_ref,e.title,e.starts_at,e.capacity,e.event_type,
                ea.user_id
         FROM events e
         JOIN event_attendance ea ON ea.event_id=e.id
         WHERE e.group_id=? AND e.status='completed'
           AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)
           AND ea.status IN ('checked_in','attended','left_early')
         ORDER BY e.starts_at,e.id,ea.user_id"
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_member_relationship_group_snapshot(array $admin, string $groupRef, ?PDO $pdo = null): array
{
    $pdo=coveted_member_relationship_require_admin($admin,$pdo);
    $group=coveted_group_by_ref($groupRef);
    if(!$group || (string)$group['status']!=='active') throw new InvalidArgumentException('Active group not found.');

    $members=coveted_member_relationship_active_members($pdo,(int)$group['id']);
    $attendance=coveted_member_relationship_attendance($pdo,(int)$group['id']);
    $now=time();
    $ninety=$now-(90*86400);
    $thirty=$now-(30*86400);
    $memberMap=[];
    foreach($members as $member){
        $id=(int)$member['user_id'];
        $memberMap[$id]=[
            'user_id'=>$id,
            'display_name'=>(string)$member['display_name'],
            'group_role'=>(string)$member['group_role'],
            'joined_at'=>(string)($member['joined_at']??''),
            'verified_events'=>0,'verified_90d'=>0,'verified_30d'=>0,'last_verified_at'=>null,
            'small_format_events'=>0,
        ];
    }

    $events=[];
    foreach($attendance as $row){
        $uid=(int)$row['user_id'];
        if(!isset($memberMap[$uid])) continue;
        $eid=(int)$row['event_id'];
        $events[$eid] ??= ['starts_at'=>(string)$row['starts_at'],'capacity'=>(int)($row['capacity']??0),'users'=>[]];
        $events[$eid]['users'][$uid]=true;
        $ts=strtotime((string)$row['starts_at']) ?: 0;
        $memberMap[$uid]['verified_events']++;
        if($ts >= $ninety) $memberMap[$uid]['verified_90d']++;
        if($ts >= $thirty) $memberMap[$uid]['verified_30d']++;
        if($ts>0 && ($memberMap[$uid]['last_verified_at']===null || $ts>strtotime((string)$memberMap[$uid]['last_verified_at']))) {
            $memberMap[$uid]['last_verified_at']=(string)$row['starts_at'];
        }
        if((int)($row['capacity']??0)>0 && (int)$row['capacity']<=18) $memberMap[$uid]['small_format_events']++;
    }

    $pairMap=[];
    foreach($events as $event){
        $ids=array_keys($event['users']); sort($ids,SORT_NUMERIC); $count=count($ids);
        for($i=0;$i<$count;$i++) for($j=$i+1;$j<$count;$j++){
            $key=$ids[$i].':'.$ids[$j];
            $pairMap[$key] ??= ['a'=>$ids[$i],'b'=>$ids[$j],'coattendance'=>0,'last_at'=>null];
            $pairMap[$key]['coattendance']++;
            if($pairMap[$key]['last_at']===null || strtotime((string)$event['starts_at'])>strtotime((string)$pairMap[$key]['last_at'])) $pairMap[$key]['last_at']=$event['starts_at'];
        }
    }

    $drifting=[];$underEngaged=[];$smallFormat=[];
    foreach($memberMap as $member){
        $last=$member['last_verified_at'] ? (strtotime((string)$member['last_verified_at']) ?: 0) : 0;
        if((int)$member['verified_events']>0 && $last>0 && $last<$ninety) $drifting[]=$member;
        if((int)$member['verified_events']===0 || ((int)$member['verified_90d']===0 && $last<$ninety)) $underEngaged[]=$member;
        if((int)$member['small_format_events']>=2) $smallFormat[]=$member;
    }

    $connections=[];$reconnectPairs=[];
    foreach($pairMap as $pair){
        if(!isset($memberMap[$pair['a']],$memberMap[$pair['b']])) continue;
        $row=[
            'member_a'=>$memberMap[$pair['a']],
            'member_b'=>$memberMap[$pair['b']],
            'coattendance'=>(int)$pair['coattendance'],
            'last_coattended_at'=>(string)$pair['last_at'],
        ];
        if((int)$pair['coattendance']>=2) $connections[]=$row;
        if((int)$pair['coattendance']>=2 && (strtotime((string)$pair['last_at']) ?: 0)<$ninety) $reconnectPairs[]=$row;
    }
    usort($connections,static fn(array $a,array $b):int=>((int)$b['coattendance']<=> (int)$a['coattendance']) ?: strcmp((string)$a['last_coattended_at'],(string)$b['last_coattended_at']));
    usort($reconnectPairs,static fn(array $a,array $b):int=>strcmp((string)$a['last_coattended_at'],(string)$b['last_coattended_at']));

    $noOverlap=[];
    $memberIds=array_keys($memberMap); sort($memberIds,SORT_NUMERIC);
    $limit=min(count($memberIds),50);
    for($i=0;$i<$limit;$i++) for($j=$i+1;$j<$limit;$j++){
        $key=$memberIds[$i].':'.$memberIds[$j];
        if(isset($pairMap[$key])) continue;
        $noOverlap[]=['member_a'=>$memberMap[$memberIds[$i]],'member_b'=>$memberMap[$memberIds[$j]]];
        if(count($noOverlap)>=40) break 2;
    }

    $completed90=0;$completedPrior=0;$lastCompleted=null;
    $stmt=$pdo->prepare("SELECT starts_at FROM events WHERE group_id=? AND status='completed' AND starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) ORDER BY starts_at DESC");
    $stmt->execute([(int)$group['id']]);
    foreach($stmt->fetchAll() as $row){
        $ts=strtotime((string)$row['starts_at'])?:0;
        $lastCompleted ??=(string)$row['starts_at'];
        if($ts >= $ninety) $completed90++; else $completedPrior++;
    }

    $activeCount=count($memberMap);
    $recentUnique=count(array_filter($memberMap,static fn(array $m):bool=>(int)$m['verified_90d']>0));
    $breadth=$activeCount>0?round(($recentUnique/$activeCount)*100,1):0.0;
    $totalRecentVisits=array_sum(array_map(static fn(array $m):int=>(int)$m['verified_90d'],$memberMap));
    $sortedRecent=array_values(array_map(static fn(array $m):int=>(int)$m['verified_90d'],$memberMap)); rsort($sortedRecent,SORT_NUMERIC);
    $topCount=max(1,(int)ceil(max(1,$activeCount)*0.25));
    $topVisits=array_sum(array_slice($sortedRecent,0,$topCount));
    $concentration=$totalRecentVisits>0?round(($topVisits/$totalRecentVisits)*100,1):0.0;

    $health='stable';
    if($completed90>=4 && $breadth<45) $health='over_scheduled';
    elseif($completed90===0 && $completedPrior>0) $health='fading';
    elseif($activeCount>=6 && $breadth<35) $health='under_engaged';
    elseif($activeCount>=8 && $concentration>=70) $health='fragmented';
    elseif($completed90>$completedPrior && $breadth>=55) $health='growing';
    elseif($completed90>0 && $breadth>=50) $health='active';

    $recommendations=[];
    $href='/admin/member-relationships.php?group='.rawurlencode((string)$group['public_id']);
    $add=static function(int $priority,string $key,string $title,string $detail,string $evidence)use(&$recommendations,$href):void{
        $recommendations[]=['priority'=>$priority,'key'=>$key,'category'=>'Relationships','title'=>$title,'detail'=>$detail,'evidence'=>$evidence,'href'=>$href];
    };
    if(count($drifting)>0) $add(1,'relationship-drift-'.(string)$group['public_id'],'Reconnect drifting members in '.(string)$group['name'],'Verified attendees have fallen out of the recent event cadence. Review the relationship workspace before the next invitation cycle.',count($drifting).' active member'.(count($drifting)===1?' has':'s have').' prior verified attendance but none in 90 days.');
    if(count($reconnectPairs)>0) $add(2,'relationship-pairs-'.(string)$group['public_id'],'Create a reconnection moment for '.(string)$group['name'],'Some members repeatedly attended together historically but have not overlapped at a verified Coveted event recently.',count($reconnectPairs).' recurring verified co-attendance pair'.(count($reconnectPairs)===1?'':'s').' have not overlapped in 90 days.');
    if($health==='fragmented' || $health==='under_engaged') $add(1,'relationship-breadth-'.(string)$group['public_id'],'Widen participation in '.(string)$group['name'],'Recent verified attendance is concentrated in a limited part of the active membership. Use the next event to broaden participation.',number_format($breadth,1).'% recent participation breadth · '.number_format($concentration,1).'% of recent visits from the most-active quarter.');
    if($health==='over_scheduled') $add(1,'relationship-fatigue-'.(string)$group['public_id'],'Reduce event pressure for '.(string)$group['name'],'Event frequency is high relative to recent participation breadth. Avoid solving weaker engagement by adding more invitations.', $completed90.' completed events in 90 days · '.number_format($breadth,1).'% participation breadth.');

    return [
        'group'=>['id'=>(int)$group['id'],'public_id'=>(string)$group['public_id'],'name'=>(string)$group['name'],'city'=>(string)($group['city']??'')],
        'health'=>$health,
        'metrics'=>[
            'active_members'=>$activeCount,'recent_unique'=>$recentUnique,'participation_breadth'=>$breadth,'attendance_concentration'=>$concentration,
            'events_90d'=>$completed90,'events_prior_90d'=>$completedPrior,'last_completed_at'=>$lastCompleted,
            'drifting_members'=>count($drifting),'under_engaged_members'=>count($underEngaged),'recurring_connections'=>count($connections),'reconnect_pairs'=>count($reconnectPairs),'no_verified_overlap_pairs'=>count($noOverlap),'small_format_evidence'=>count($smallFormat),
        ],
        'members'=>array_values($memberMap),'drifting'=>$drifting,'under_engaged'=>$underEngaged,'connections'=>array_slice($connections,0,40),'reconnect_pairs'=>array_slice($reconnectPairs,0,40),'no_verified_overlap'=>array_slice($noOverlap,0,40),'small_format_members'=>$smallFormat,
        'recommendations'=>$recommendations,
        'privacy'=>'Relationship intelligence uses verified Coveted attendance only. No Mutual Reconnect choices, contact details, private messages, or public popularity rankings are used.',
    ];
}

/** @return array<string,mixed> */
function coveted_member_relationship_agent_context(array $admin, int $limit = 20, ?PDO $pdo = null): array
{
    if(!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(coveted_system_sample_mode($admin,$pdo)) return ['available'=>false,'reason'=>'sample_mode','groups'=>[],'recommendations'=>[],'attention'=>0];

    $groups=[];$recommendations=[];$attention=0;
    foreach(array_slice(coveted_member_relationship_groups($admin,$pdo),0,max(1,min(50,$limit))) as $group){
        try{$snapshot=coveted_member_relationship_group_snapshot($admin,(string)$group['public_id'],$pdo);}catch(Throwable){continue;}
        $m=(array)$snapshot['metrics'];
        if(in_array((string)$snapshot['health'],['fading','fragmented','under_engaged','over_scheduled'],true) || (int)$m['drifting_members']>0) $attention++;
        // Broad Agent context intentionally contains no member identities or pair identities.
        $groups[]=[
            'group_ref'=>(string)$snapshot['group']['public_id'],'group'=>(string)$snapshot['group']['name'],'health'=>(string)$snapshot['health'],
            'active_members'=>(int)$m['active_members'],'participation_breadth'=>(float)$m['participation_breadth'],'attendance_concentration'=>(float)$m['attendance_concentration'],
            'events_90d'=>(int)$m['events_90d'],'events_prior_90d'=>(int)$m['events_prior_90d'],'drifting_members'=>(int)$m['drifting_members'],'under_engaged_members'=>(int)$m['under_engaged_members'],
            'recurring_connections'=>(int)$m['recurring_connections'],'reconnect_pairs'=>(int)$m['reconnect_pairs'],'no_verified_overlap_pairs'=>(int)$m['no_verified_overlap_pairs'],'small_format_evidence'=>(int)$m['small_format_evidence'],
            'href'=>'/admin/member-relationships.php?group='.rawurlencode((string)$snapshot['group']['public_id']),
        ];
        foreach(array_slice((array)$snapshot['recommendations'],0,3) as $rec) if(count($recommendations)<12) $recommendations[]=$rec;
    }

    $guestMix=['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0];
    try{
        $guestMix=coveted_event_guest_mix_agent_context($admin,12,$pdo);
        $attention+=(int)($guestMix['attention']??0);
        foreach(array_slice((array)($guestMix['recommendations']??[]),0,8) as $rec) $recommendations[]=$rec;
    }catch(Throwable $e){
        error_log('Member Relationship Guest Mix Agent bridge unavailable: '.$e->getMessage());
    }

    $memberJourney=['available'=>false,'summary'=>[],'recommendations'=>[],'attention'=>0];
    try{
        $memberJourney=coveted_member_journey_agent_context_fast($admin,60,$pdo);
        $attention+=(int)($memberJourney['attention']??0);
        foreach(array_slice((array)($memberJourney['recommendations']??[]),0,8) as $rec)$recommendations[]=$rec;
    }catch(Throwable $e){
        error_log('Member Relationship Member Journey Agent bridge unavailable: '.$e->getMessage());
    }

    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return [
        'available'=>true,'groups'=>$groups,'recommendations'=>array_slice($recommendations,0,20),'attention'=>$attention,
        'guest_mix'=>['available'=>!empty($guestMix['available']),'events'=>array_slice((array)($guestMix['events']??[]),0,12),'privacy'=>(string)($guestMix['privacy']??''),'authority'=>(string)($guestMix['authority']??'')],
        'member_journey'=>['available'=>!empty($memberJourney['available']),'scanned'=>(int)($memberJourney['scanned']??0),'summary'=>(array)($memberJourney['summary']??[]),'attention'=>(int)($memberJourney['attention']??0),'privacy'=>(string)($memberJourney['privacy']??''),'authority'=>(string)($memberJourney['authority']??''),'performance'=>(string)($memberJourney['performance']??'')],
        'privacy'=>'Aggregate group/event/member-journey relationship and Guest Mix signals only. Member Journey recommendations use opaque member refs and exclude member names, emails, pair identities, Mutual Reconnect choices, contact details, private messages, personality inference, and public rankings.'
    ];
}
