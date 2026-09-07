<?php
declare(strict_types=1);

require_once __DIR__ . '/system_sample_data.php';

/**
 * Member Journey Intelligence is a read-only System Admin view over canonical
 * membership, invitation, RSVP, attendance, notification and reward state.
 * It never persists a hidden member score or public ranking.
 */
function coveted_member_journey_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Member Journey Intelligence.');
    }
    return $pdo;
}

/** @return array<string,mixed> */
function coveted_member_journey_metrics_row(PDO $pdo, string $memberRef): array
{
    $memberRef = trim($memberRef);
    if ($memberRef === '' || strlen($memberRef) > 64) {
        throw new InvalidArgumentException('Member not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT
            u.id,u.public_id,u.display_name,u.created_at,
            (SELECT COUNT(*) FROM group_memberships gm
             WHERE gm.user_id=u.id AND gm.membership_status='active' AND gm.group_role<>'guest') AS active_groups,
            (SELECT COUNT(*) FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             WHERE ea.user_id=u.id AND e.status='completed'
               AND ea.status IN ('checked_in','attended','left_early')
               AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)) AS verified_365d,
            (SELECT COUNT(*) FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             WHERE ea.user_id=u.id AND e.status='completed'
               AND ea.status IN ('checked_in','attended','left_early')
               AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) AS verified_90d,
            (SELECT COUNT(*) FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             WHERE ea.user_id=u.id AND e.status='completed'
               AND ea.status IN ('checked_in','attended','left_early')
               AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS verified_30d,
            (SELECT MAX(e.starts_at) FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             WHERE ea.user_id=u.id AND e.status='completed'
               AND ea.status IN ('checked_in','attended','left_early')) AS last_verified_at,
            (SELECT COUNT(*) FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             WHERE ea.user_id=u.id AND e.status='completed'
               AND ea.status IN ('checked_in','attended','left_early')
               AND e.capacity IS NOT NULL AND e.capacity<=18
               AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)) AS small_format_365d,
            (SELECT COUNT(*) FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             WHERE ea.user_id=u.id AND ea.status='no_show'
               AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)) AS no_shows_180d,
            (SELECT COUNT(*) FROM event_rsvps er JOIN events e ON e.id=er.event_id
             WHERE er.user_id=u.id AND er.response='declined'
               AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)) AS declines_180d,
            (SELECT COUNT(*) FROM event_invitations ei
             WHERE ei.user_id=u.id AND ei.status<>'revoked'
               AND ei.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS invitations_30d,
            (SELECT COUNT(*) FROM event_invitations ei
             WHERE ei.user_id=u.id AND ei.status<>'revoked'
               AND ei.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 DAY)) AS invitations_60d,
            (SELECT COUNT(*) FROM event_invitations ei JOIN events e ON e.id=ei.event_id
             WHERE ei.user_id=u.id AND ei.status='pending' AND e.starts_at>UTC_TIMESTAMP()) AS pending_invitations,
            (SELECT COUNT(*) FROM event_invitations ei JOIN events e ON e.id=ei.event_id
             WHERE ei.user_id=u.id AND ei.status<>'revoked' AND e.starts_at>UTC_TIMESTAMP()) AS future_invitations,
            (SELECT COUNT(*) FROM notifications n
             WHERE n.user_id=u.id AND n.notification_type LIKE 'event.%'
               AND n.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS event_messages_30d,
            (SELECT COUNT(*) FROM reward_issuances ri
             WHERE ri.user_id=u.id AND ri.issued_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)) AS rewards_issued_180d,
            (SELECT COUNT(*) FROM reward_issuances ri
             WHERE ri.user_id=u.id AND ri.status='claimed'
               AND ri.issued_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)) AS rewards_claimed_180d
         FROM users u
         WHERE (u.public_id=? OR CAST(u.id AS CHAR)=?) AND u.status='active'
         LIMIT 1"
    );
    $stmt->execute([$memberRef,$memberRef]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Active member not found.');
    }
    foreach([
        'id','active_groups','verified_365d','verified_90d','verified_30d','small_format_365d',
        'no_shows_180d','declines_180d','invitations_30d','invitations_60d','pending_invitations',
        'future_invitations','event_messages_30d','rewards_issued_180d','rewards_claimed_180d'
    ] as $key) {
        $row[$key]=(int)$row[$key];
    }
    return $row;
}

/** @return array<string,mixed> */
function coveted_member_journey_decision(array $metrics): array
{
    $last = trim((string)($metrics['last_verified_at'] ?? ''));
    $daysSince = null;
    if ($last !== '') {
        $ts = strtotime($last) ?: 0;
        if ($ts > 0) $daysSince = max(0,(int)floor((time()-$ts)/86400));
    }

    $invitePressure=(int)($metrics['invitations_30d']??0);
    $future=(int)($metrics['future_invitations']??0);
    $messagePressure=(int)($metrics['event_messages_30d']??0);
    $verified365=(int)($metrics['verified_365d']??0);
    $verified90=(int)($metrics['verified_90d']??0);
    $verified30=(int)($metrics['verified_30d']??0);
    $small=(int)($metrics['small_format_365d']??0);
    $noShows=(int)($metrics['no_shows_180d']??0);
    $declines=(int)($metrics['declines_180d']??0);
    $rewardClaims=(int)($metrics['rewards_claimed_180d']??0);

    $state='steady';
    $action='hold_steady';
    $priority=3;
    $title='Keep the current relationship cadence';
    $detail='Current verified participation and invitation pressure do not require a stronger intervention.';
    $evidence=[];

    if ($invitePressure>=4 || $future>=2 || $messagePressure>=8) {
        $state='over_contacted';
        $action='pause_invitations';
        $priority=1;
        $title='Reduce invitation pressure';
        $detail='Recent outreach is already high. Do not solve engagement by stacking another invitation or communication right now.';
    } elseif ($noShows>=2) {
        $state='recovery';
        $action='recover_after_no_show';
        $priority=1;
        $title='Review a low-pressure relationship recovery';
        $detail='Repeated recent no-shows warrant a relationship review before another routine invitation.';
    } elseif ($verified365>0 && $verified90===0) {
        $state='drifting';
        $action=$small>=2?'reconnect_small_format':'reconnect';
        $priority=1;
        $title=$small>=2?'Reconnect through a smaller-format Event':'Review a reconnection opportunity';
        $detail=$small>=2
            ? 'This member has prior verified small-format participation but no verified attendance in 90 days. A smaller Event is the strongest evidence-based re-entry path.'
            : 'This member has prior verified participation but no verified attendance in 90 days. Review a measured reconnection opportunity before broad outreach.';
    } elseif ($verified365===0 && (int)($metrics['active_groups']??0)>0) {
        $state='unactivated';
        $action='first_event_fit';
        $priority=2;
        $title='Find a first verified participation opportunity';
        $detail='The member is active in Coveted groups but has no verified completed-event attendance in the measured year.';
    } elseif ($daysSince!==null && $daysSince<=7) {
        $state='post_event';
        $action=$rewardClaims>0?'post_event_value_followup':'post_event_followup';
        $priority=2;
        $title=$rewardClaims>0?'Review post-event value follow-up':'Review post-event relationship follow-up';
        $detail=$rewardClaims>0
            ? 'Recent verified attendance and prior reward claims suggest a timely partner-value or relationship follow-up may be useful.'
            : 'The member attended recently. Review the next relationship step while the Event is still fresh without automatically adding another invitation.';
    } elseif ($verified30>=2) {
        $state='momentum';
        $action='invite_again_soon';
        $priority=2;
        $title='Protect positive participation momentum';
        $detail='Recent verified attendance is strong and current invitation pressure remains within pacing limits.';
    } elseif ($rewardClaims>=2 && $verified90>0) {
        $state='value_engaged';
        $action='partner_value';
        $priority=2;
        $title='Use proven member-value engagement';
        $detail='Recent verified participation and repeated reward claims show that partner value is part of this member’s demonstrated Coveted journey.';
    } elseif ($declines>=3) {
        $state='decline_pressure';
        $action='pause_invitations';
        $priority=2;
        $title='Pause routine invitations';
        $detail='Repeated recent declines suggest reducing event pressure before the next invitation cycle.';
    }

    $evidence[]=$verified365.' verified event'.($verified365===1?'':'s').' in 365d';
    $evidence[]=$verified90.' in 90d';
    $evidence[]=$invitePressure.' invitation'.($invitePressure===1?'':'s').' in 30d';
    if($future>0)$evidence[]=$future.' future invitation'.($future===1?'':'s');
    if($noShows>0)$evidence[]=$noShows.' no-show'.($noShows===1?'':'s').' in 180d';
    if($declines>0)$evidence[]=$declines.' decline'.($declines===1?'':'s').' in 180d';
    if($rewardClaims>0)$evidence[]=$rewardClaims.' reward claim'.($rewardClaims===1?'':'s').' in 180d';
    if($daysSince!==null)$evidence[]=$daysSince.' day'.($daysSince===1?'':'s').' since last verified attendance';

    return [
        'state'=>$state,'action'=>$action,'priority'=>$priority,'title'=>$title,'detail'=>$detail,
        'evidence'=>implode(' · ',$evidence),'days_since_verified'=>$daysSince,
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_member_journey_groups(PDO $pdo, int $userId): array
{
    $stmt=$pdo->prepare(
        "SELECT g.public_id,g.name,gm.group_role,gm.membership_status,gm.joined_at
         FROM group_memberships gm JOIN social_groups g ON g.id=gm.group_id
         WHERE gm.user_id=? AND gm.membership_status IN ('active','away') AND g.status<>'archived'
         ORDER BY FIELD(gm.membership_status,'active','away'),g.name,g.id"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_member_journey_preferences(PDO $pdo, int $userId): array
{
    $stmt=$pdo->prepare(
        "SELECT e.event_type,COUNT(*) AS visits
         FROM event_attendance ea JOIN events e ON e.id=ea.event_id
         WHERE ea.user_id=? AND e.status='completed'
           AND ea.status IN ('checked_in','attended','left_early')
           AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)
         GROUP BY e.event_type ORDER BY visits DESC,e.event_type"
    );
    $stmt->execute([$userId]);
    $formats=$stmt->fetchAll();
    foreach($formats as &$row)$row['visits']=(int)$row['visits'];
    unset($row);

    $size=$pdo->prepare(
        "SELECT
           SUM(CASE WHEN e.capacity IS NOT NULL AND e.capacity<=18 THEN 1 ELSE 0 END) AS small_visits,
           SUM(CASE WHEN e.capacity BETWEEN 19 AND 40 THEN 1 ELSE 0 END) AS medium_visits,
           SUM(CASE WHEN e.capacity>40 THEN 1 ELSE 0 END) AS large_visits
         FROM event_attendance ea JOIN events e ON e.id=ea.event_id
         WHERE ea.user_id=? AND e.status='completed'
           AND ea.status IN ('checked_in','attended','left_early')
           AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)"
    );
    $size->execute([$userId]);
    $sizes=$size->fetch()?:[];
    foreach(['small_visits','medium_visits','large_visits'] as $key)$sizes[$key]=(int)($sizes[$key]??0);

    $response=$pdo->prepare(
        "SELECT AVG(TIMESTAMPDIFF(HOUR,ei.created_at,er.responded_at))
         FROM event_rsvps er JOIN event_invitations ei ON ei.event_id=er.event_id AND ei.user_id=er.user_id
         WHERE er.user_id=? AND er.responded_at>=ei.created_at
           AND er.responded_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)"
    );
    $response->execute([$userId]);
    $avg=$response->fetchColumn();

    return [
        'formats'=>$formats,
        'sizes'=>$sizes,
        'average_response_hours'=>$avg!==false&&$avg!==null?round((float)$avg,1):null,
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_member_journey_timeline(PDO $pdo, int $userId, int $limit=60): array
{
    $rows=[];
    $queries=[
        ['invitation',"SELECT ei.created_at AS occurred_at,e.public_id AS event_ref,e.title,ei.status AS detail
                       FROM event_invitations ei JOIN events e ON e.id=ei.event_id
                       WHERE ei.user_id=? ORDER BY ei.created_at DESC LIMIT 30"],
        ['rsvp',"SELECT er.responded_at AS occurred_at,e.public_id AS event_ref,e.title,er.response AS detail
                FROM event_rsvps er JOIN events e ON e.id=er.event_id
                WHERE er.user_id=? ORDER BY er.responded_at DESC LIMIT 30"],
        ['attendance',"SELECT COALESCE(ea.checked_in_at,ea.updated_at) AS occurred_at,e.public_id AS event_ref,e.title,ea.status AS detail
                      FROM event_attendance ea JOIN events e ON e.id=ea.event_id
                      WHERE ea.user_id=? ORDER BY COALESCE(ea.checked_in_at,ea.updated_at) DESC LIMIT 30"],
        ['reward',"SELECT ri.issued_at AS occurred_at,COALESCE(e.public_id,'') AS event_ref,
                   COALESCE(e.title,'Member reward') AS title,ri.status AS detail
                  FROM reward_issuances ri LEFT JOIN events e ON e.id=ri.event_id
                  WHERE ri.user_id=? ORDER BY ri.issued_at DESC LIMIT 30"],
    ];
    foreach($queries as [$kind,$sql]){
        $stmt=$pdo->prepare($sql);$stmt->execute([$userId]);
        foreach($stmt->fetchAll() as $row){
            $rows[]=[
                'kind'=>$kind,'occurred_at'=>(string)$row['occurred_at'],'event_ref'=>(string)$row['event_ref'],
                'title'=>(string)$row['title'],'detail'=>(string)$row['detail'],
            ];
        }
    }
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)$b['occurred_at'],(string)$a['occurred_at']));
    return array_slice($rows,0,max(1,min(100,$limit)));
}

/** @return array<string,mixed> */
function coveted_member_journey_snapshot(array $admin,string $memberRef,?PDO $pdo=null): array
{
    $pdo=coveted_member_journey_require_admin($admin,$pdo);
    $metrics=coveted_member_journey_metrics_row($pdo,$memberRef);
    $decision=coveted_member_journey_decision($metrics);
    return [
        'member'=>[
            'id'=>(int)$metrics['id'],'public_id'=>(string)$metrics['public_id'],
            'display_name'=>(string)$metrics['display_name'],'created_at'=>(string)$metrics['created_at'],
        ],
        'metrics'=>$metrics,
        'decision'=>$decision,
        'groups'=>coveted_member_journey_groups($pdo,(int)$metrics['id']),
        'preferences'=>coveted_member_journey_preferences($pdo,(int)$metrics['id']),
        'timeline'=>coveted_member_journey_timeline($pdo,(int)$metrics['id']),
        'privacy'=>'Member Journey Intelligence is System Admin-only and evidence-based. It does not create a public popularity score, infer personality, read private messages, or use Mutual Reconnect choices.',
        'authority'=>'The Agent may recommend and track a next-best action, but member outreach, rewards and invitations remain explicit System Admin actions through existing canonical workflows.',
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_member_journey_admin_index(array $admin,int $limit=80,?PDO $pdo=null): array
{
    $pdo=coveted_member_journey_require_admin($admin,$pdo);
    $stmt=$pdo->query(
        "SELECT u.public_id,u.display_name
         FROM users u
         WHERE u.status='active'
           AND EXISTS(SELECT 1 FROM group_memberships gm WHERE gm.user_id=u.id AND gm.membership_status='active' AND gm.group_role<>'guest')
         ORDER BY u.display_name,u.id LIMIT 120"
    );
    $rows=[];
    foreach($stmt->fetchAll() as $user){
        try{
            $metrics=coveted_member_journey_metrics_row($pdo,(string)$user['public_id']);
            $decision=coveted_member_journey_decision($metrics);
        }catch(Throwable){continue;}
        $rows[]=[
            'member_ref'=>(string)$user['public_id'],'display_name'=>(string)$user['display_name'],
            'state'=>(string)$decision['state'],'action'=>(string)$decision['action'],'priority'=>(int)$decision['priority'],
            'title'=>(string)$decision['title'],'evidence'=>(string)$decision['evidence'],
            'verified_90d'=>(int)$metrics['verified_90d'],'invitations_30d'=>(int)$metrics['invitations_30d'],
            'future_invitations'=>(int)$metrics['future_invitations'],'reward_claims_180d'=>(int)$metrics['rewards_claimed_180d'],
        ];
    }
    usort($rows,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['display_name'],(string)$b['display_name']));
    return array_slice($rows,0,max(1,min(120,$limit)));
}

/** @return array<string,mixed> */
function coveted_member_journey_agent_context(array $admin,int $limit=40,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(coveted_system_sample_mode($admin,$pdo))return ['available'=>false,'reason'=>'sample_mode','summary'=>[],'recommendations'=>[],'attention'=>0];

    $stmt=$pdo->query(
        "SELECT u.public_id
         FROM users u
         WHERE u.status='active'
           AND EXISTS(SELECT 1 FROM group_memberships gm WHERE gm.user_id=u.id AND gm.membership_status='active' AND gm.group_role<>'guest')
         ORDER BY u.id LIMIT 80"
    );
    $summary=['over_contacted'=>0,'recovery'=>0,'drifting'=>0,'unactivated'=>0,'post_event'=>0,'momentum'=>0,'value_engaged'=>0,'decline_pressure'=>0,'steady'=>0];
    $recommendations=[];$attention=0;$scanned=0;
    foreach($stmt->fetchAll() as $user){
        if($scanned>=max(1,min(80,$limit)))break;
        $scanned++;
        try{$metrics=coveted_member_journey_metrics_row($pdo,(string)$user['public_id']);}catch(Throwable){continue;}
        $decision=coveted_member_journey_decision($metrics);
        $state=(string)$decision['state'];
        $summary[$state]=($summary[$state]??0)+1;
        if((int)$decision['priority']<=2 && $state!=='steady'){
            $attention++;
            if(count($recommendations)<16){
                $recommendations[]=[
                    'priority'=>(int)$decision['priority'],
                    'key'=>'member-journey-'.(string)$metrics['public_id'],
                    'category'=>'Member Journey',
                    'title'=>'Review a member journey next-best action',
                    'detail'=>(string)$decision['detail'],
                    'evidence'=>(string)$decision['evidence'].' · action '.(string)$decision['action'],
                    'href'=>'/admin/member-journeys.php?member='.rawurlencode((string)$metrics['public_id']),
                ];
            }
        }
    }
    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return [
        'available'=>true,'scanned'=>$scanned,'summary'=>$summary,'recommendations'=>$recommendations,'attention'=>$attention,
        'privacy'=>'Aggregate/opaque member journey signals only. Broad Agent context contains no member names, email addresses, contact details, private messages, personality inference, or public ranking.',
        'authority'=>'Read-only next-best-action intelligence. The Agent may recommend and track work, but System Admin explicitly performs any invitation, communication, reward or relationship action.',
    ];
}
