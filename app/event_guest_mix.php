<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/system_sample_data.php';

/**
 * Guest Mix is a read-only, event-specific invitation-fit model. It never
 * sends invitations and never assigns a general member/popularity score.
 */
function coveted_event_guest_mix_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if(!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode does not expose live Guest Mix intelligence.');
    return $pdo;
}

/** @return array<string,mixed> */
function coveted_event_guest_mix_event(array $admin,string $eventRef,?PDO $pdo=null): array
{
    $pdo=coveted_event_guest_mix_require_admin($admin,$pdo);
    $eventRef=trim($eventRef);
    if($eventRef==='' || strlen($eventRef)>64) throw new InvalidArgumentException('Event not found.');
    $stmt=$pdo->prepare(
        "SELECT e.*,g.public_id AS group_ref,g.name AS group_name
         FROM events e JOIN social_groups g ON g.id=e.group_id
         WHERE (e.public_id=? OR CAST(e.id AS CHAR)=?) AND e.status IN ('draft','published') LIMIT 1"
    );
    $stmt->execute([$eventRef,$eventRef]);
    $event=$stmt->fetch();
    if(!$event) throw new InvalidArgumentException('Future draft or published event not found.');
    $start=strtotime((string)$event['starts_at'])?:0;
    if($start>0 && $start<=time()) throw new InvalidArgumentException('Guest Mix is available only before the event starts.');
    return $event;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_guest_mix_candidate_rows(PDO $pdo,array $event): array
{
    $stmt=$pdo->prepare(
        "SELECT gm.user_id,gm.group_role,gm.joined_at,u.display_name,
                (SELECT COUNT(*) FROM event_attendance ea JOIN events pe ON pe.id=ea.event_id
                 WHERE ea.user_id=gm.user_id AND pe.group_id=gm.group_id AND pe.status='completed'
                   AND ea.status IN ('checked_in','attended','left_early') AND pe.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)) AS verified_events,
                (SELECT COUNT(*) FROM event_attendance ea JOIN events pe ON pe.id=ea.event_id
                 WHERE ea.user_id=gm.user_id AND pe.group_id=gm.group_id AND pe.status='completed'
                   AND ea.status IN ('checked_in','attended','left_early') AND pe.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) AS verified_90d,
                (SELECT MAX(pe.starts_at) FROM event_attendance ea JOIN events pe ON pe.id=ea.event_id
                 WHERE ea.user_id=gm.user_id AND pe.group_id=gm.group_id AND pe.status='completed'
                   AND ea.status IN ('checked_in','attended','left_early')) AS last_verified_at,
                (SELECT COUNT(*) FROM event_attendance ea JOIN events pe ON pe.id=ea.event_id
                 WHERE ea.user_id=gm.user_id AND pe.group_id=gm.group_id AND pe.status='completed'
                   AND ea.status IN ('checked_in','attended','left_early') AND pe.capacity IS NOT NULL AND pe.capacity<=18
                   AND pe.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)) AS small_format_events,
                (SELECT COUNT(*) FROM event_attendance ea JOIN events pe ON pe.id=ea.event_id
                 WHERE ea.user_id=gm.user_id AND pe.group_id=gm.group_id AND pe.status='completed'
                   AND ea.status IN ('checked_in','attended','left_early') AND pe.event_type=?
                   AND pe.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)) AS matching_format_events,
                (SELECT COUNT(*) FROM event_attendance ea JOIN events pe ON pe.id=ea.event_id
                 WHERE ea.user_id=gm.user_id AND pe.group_id=gm.group_id AND pe.status='completed'
                   AND ea.status='no_show' AND pe.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)) AS no_shows_180d,
                (SELECT COUNT(*) FROM event_rsvps er JOIN events pe ON pe.id=er.event_id
                 WHERE er.user_id=gm.user_id AND pe.group_id=gm.group_id AND er.response='declined'
                   AND pe.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)) AS declines_180d,
                (SELECT COUNT(*) FROM event_invitations ei JOIN events ie ON ie.id=ei.event_id
                 WHERE ei.user_id=gm.user_id AND ie.group_id=gm.group_id AND ei.status<>'revoked'
                   AND ie.starts_at BETWEEN DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 DAY) AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 DAY)) AS invitations_60d,
                (SELECT COUNT(*) FROM event_invitations ei JOIN events ie ON ie.id=ei.event_id
                 WHERE ei.user_id=gm.user_id AND ie.group_id=gm.group_id AND ei.status<>'revoked'
                   AND ie.starts_at>UTC_TIMESTAMP() AND ie.id<>?) AS other_future_invitations
         FROM group_memberships gm JOIN users u ON u.id=gm.user_id
         WHERE gm.group_id=? AND gm.membership_status='active' AND gm.group_role<>'guest' AND u.status='active'
           AND NOT EXISTS (SELECT 1 FROM event_hosts eh WHERE eh.event_id=? AND eh.user_id=gm.user_id)
           AND NOT EXISTS (SELECT 1 FROM event_invitations ei WHERE ei.event_id=? AND ei.user_id=gm.user_id AND ei.status<>'revoked')
           AND NOT EXISTS (SELECT 1 FROM event_rsvps er WHERE er.event_id=? AND er.user_id=gm.user_id AND er.response IN ('attending','waitlist','declined'))
         ORDER BY u.display_name,u.id"
    );
    $stmt->execute([(string)$event['event_type'],(int)$event['id'],(int)$event['group_id'],(int)$event['id'],(int)$event['id'],(int)$event['id']]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_event_guest_mix_snapshot(array $admin,string $eventRef,?PDO $pdo=null): array
{
    $pdo=coveted_event_guest_mix_require_admin($admin,$pdo);
    $event=coveted_event_guest_mix_event($admin,$eventRef,$pdo);

    $state=$pdo->prepare(
        "SELECT
          (SELECT COUNT(*) FROM event_invitations WHERE event_id=e.id AND status<>'revoked') AS invited,
          (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='attending') AS attending,
          (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='waitlist') AS waitlist,
          (SELECT COUNT(*) FROM event_hosts WHERE event_id=e.id) AS hosts
         FROM events e WHERE e.id=?"
    );
    $state->execute([(int)$event['id']]);
    $counts=$state->fetch()?:[];
    foreach($counts as $k=>$v)$counts[$k]=(int)$v;

    $capacity=(int)($event['capacity']??0);
    $openSeats=$capacity>0?max(0,$capacity-(int)$counts['attending']):20;
    $smallEvent=$capacity>0 && $capacity<=18;
    $now=time();$ninety=$now-(90*86400);
    $candidates=[];$paced=0;

    foreach(coveted_event_guest_mix_candidate_rows($pdo,$event) as $row){
        foreach(['user_id','verified_events','verified_90d','small_format_events','matching_format_events','no_shows_180d','declines_180d','invitations_60d','other_future_invitations'] as $key)$row[$key]=(int)$row[$key];
        $last=$row['last_verified_at']?(strtotime((string)$row['last_verified_at'])?:0):0;
        $drifting=$row['verified_events']>0 && $last>0 && $last<$ninety;
        $underEngaged=$row['verified_events']===0 || ($row['verified_90d']===0 && !$drifting);
        $pacedOut=$row['invitations_60d']>=3 || $row['other_future_invitations']>=2;
        if($pacedOut)$paced++;

        $score=40;
        $reasons=[];$segment='balanced';
        if($drifting){$score+=24;$segment='reconnect';$reasons[]='prior verified participation, no verified attendance in 90 days';}
        elseif($underEngaged){$score+=17;$segment='widen_circle';$reasons[]='active member with limited verified participation';}
        if($row['verified_events']>=3){$score+=10;$reasons[]=$row['verified_events'].' verified group events';}
        elseif($row['verified_events']>0){$score+=5;$reasons[]=$row['verified_events'].' verified group event'.($row['verified_events']===1?'':'s');}
        if($row['matching_format_events']>=2){$score+=9;$reasons[]='repeat attendance at this event format';if($segment==='balanced')$segment='reliable_repeat';}
        if($smallEvent && $row['small_format_events']>=2){$score+=13;$reasons[]='verified history at small-capacity events';if($segment==='balanced')$segment='small_format';}
        if($row['verified_90d']>=2 && $segment==='balanced'){$score+=5;$segment='reliable_repeat';$reasons[]='recent verified participation';}
        if($row['no_shows_180d']>0){$score-=min(20,$row['no_shows_180d']*8);$reasons[]=$row['no_shows_180d'].' recent no-show'.($row['no_shows_180d']===1?'':'s');}
        if($row['declines_180d']>0){$score-=min(15,$row['declines_180d']*5);$reasons[]=$row['declines_180d'].' recent decline'.($row['declines_180d']===1?'':'s');}
        if($row['invitations_60d']>=3){$score-=25;$segment='pace';$reasons[]='high recent invitation frequency';}
        elseif($row['invitations_60d']>=2){$score-=12;$reasons[]='recent invitation frequency is elevated';}
        if($row['other_future_invitations']>=2){$score-=20;$segment='pace';$reasons[]='already invited to multiple future group events';}
        elseif($row['other_future_invitations']===1){$score-=7;$reasons[]='already has another future group invitation';}
        $score=max(0,min(100,$score));
        $row['fit_score']=$score;
        $row['segment']=$segment;
        $row['drifting']=$drifting;
        $row['under_engaged']=$underEngaged;
        $row['paced_out']=$pacedOut;
        $row['reasons']=$reasons;
        $candidates[]=$row;
    }
    usort($candidates,static fn(array $a,array $b):int=>((int)$b['fit_score']<=> (int)$a['fit_score']) ?: strcmp((string)$a['display_name'],(string)$b['display_name']));

    $eligible=array_values(array_filter($candidates,static fn(array $c):bool=>!$c['paced_out'] && (int)$c['fit_score']>=35));
    $recommendedCount=min(count($eligible),max(0,$openSeats));
    if($capacity===0)$recommendedCount=min(count($eligible),20);
    $recommended=array_slice($eligible,0,$recommendedCount);
    $segments=['reconnect'=>0,'widen_circle'=>0,'reliable_repeat'=>0,'small_format'=>0,'balanced'=>0,'pace'=>0];
    foreach($candidates as $c)$segments[(string)$c['segment']] = ($segments[(string)$c['segment']]??0)+1;

    return [
        'event'=>[
            'id'=>(int)$event['id'],'public_id'=>(string)$event['public_id'],'title'=>(string)$event['title'],'status'=>(string)$event['status'],'event_type'=>(string)$event['event_type'],'starts_at'=>(string)$event['starts_at'],'capacity'=>$capacity,
            'group_id'=>(int)$event['group_id'],'group_ref'=>(string)$event['group_ref'],'group_name'=>(string)$event['group_name'],
        ],
        'counts'=>$counts+['open_seats'=>$openSeats,'candidate_pool'=>count($candidates),'recommended_count'=>$recommendedCount,'paced_members'=>$paced],
        'segments'=>$segments,
        'candidates'=>$candidates,'recommended'=>$recommended,
        'privacy'=>'Fit scores are event-specific invitation recommendations, not member-worth or popularity scores. Broad Agent context contains aggregate segment/count evidence only; member identities remain in this System Admin workspace.',
        'authority'=>'Guest Mix is read-only. System Admin decides whom to invite and uses the canonical Event invitation workflow; no invitation is sent automatically.',
    ];
}

/** @return array<string,mixed> */
function coveted_event_guest_mix_agent_context(array $admin,int $limit=12,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(coveted_system_sample_mode($admin,$pdo)) return ['available'=>false,'reason'=>'sample_mode','events'=>[],'recommendations'=>[],'attention'=>0];
    $stmt=$pdo->query(
        "SELECT public_id FROM events WHERE status IN ('draft','published') AND starts_at>UTC_TIMESTAMP() AND starts_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 45 DAY) ORDER BY starts_at,id LIMIT 30"
    );
    $events=[];$recommendations=[];$attention=0;
    foreach($stmt->fetchAll() as $row){
        if(count($events)>=max(1,min(20,$limit)))break;
        try{$snap=coveted_event_guest_mix_snapshot($admin,(string)$row['public_id'],$pdo);}catch(Throwable){continue;}
        $event=(array)$snap['event'];$counts=(array)$snap['counts'];$segments=(array)$snap['segments'];
        $needs=(int)$counts['open_seats']>0 && (int)$counts['recommended_count']>0;
        if($needs)$attention++;
        $events[]=[
            'event_ref'=>(string)$event['public_id'],'title'=>(string)$event['title'],'group'=>(string)$event['group_name'],'status'=>(string)$event['status'],'starts_at'=>(string)$event['starts_at'],'capacity'=>(int)$event['capacity'],
            'attending'=>(int)$counts['attending'],'invited'=>(int)$counts['invited'],'open_seats'=>(int)$counts['open_seats'],'candidate_pool'=>(int)$counts['candidate_pool'],'recommended_count'=>(int)$counts['recommended_count'],'paced_members'=>(int)$counts['paced_members'],
            'segments'=>['reconnect'=>(int)($segments['reconnect']??0),'widen_circle'=>(int)($segments['widen_circle']??0),'reliable_repeat'=>(int)($segments['reliable_repeat']??0),'small_format'=>(int)($segments['small_format']??0)],
            'href'=>'/admin/event-guest-mix.php?event='.rawurlencode((string)$event['public_id']),
        ];
        if($needs && count($recommendations)<12){
            $recommendations[]=[
                'priority'=>(int)$counts['attending']===0?1:2,
                'key'=>'guest-mix-'.(string)$event['public_id'],'category'=>'Invitations','title'=>'Review the guest mix for '.(string)$event['title'],
                'detail'=>'The event has open capacity and eligible active group members. Review the event-specific mix before sending invitations through the canonical Event workspace.',
                'evidence'=>(int)$counts['open_seats'].' open seats · '.(int)$counts['recommended_count'].' recommended candidates · '.(int)($segments['reconnect']??0).' reconnect · '.(int)($segments['widen_circle']??0).' participation-breadth candidates · '.(int)$counts['paced_members'].' members currently paced.',
                'href'=>'/admin/event-guest-mix.php?event='.rawurlencode((string)$event['public_id']),
            ];
        }
    }
    return ['available'=>true,'events'=>$events,'recommendations'=>$recommendations,'attention'=>$attention,'privacy'=>'Aggregate event-level invitation-fit counts only. No member identities or private Mutual Reconnect choices are included.','authority'=>'Read-only recommendation context. System Admin sends invitations through canonical Event controls.'];
}
