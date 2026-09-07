<?php
declare(strict_types=1);

require_once __DIR__ . '/member_journey.php';

/**
 * Aggregate scan used by Admin queue and Agent context. This intentionally
 * performs one bounded SQL read instead of one metrics query per member.
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_member_journey_scan_rows(PDO $pdo,int $limit=80): array
{
    $limit=max(1,min(200,$limit));
    $sql=
        "SELECT u.id,u.public_id,u.display_name,u.created_at,
                COALESCE(gm.active_groups,0) AS active_groups,
                COALESCE(att.verified_365d,0) AS verified_365d,
                COALESCE(att.verified_90d,0) AS verified_90d,
                COALESCE(att.verified_30d,0) AS verified_30d,
                att.last_verified_at,
                COALESCE(att.small_format_365d,0) AS small_format_365d,
                COALESCE(att.no_shows_180d,0) AS no_shows_180d,
                COALESCE(rsp.declines_180d,0) AS declines_180d,
                COALESCE(contacts.invitations_30d,0) AS invitations_30d,
                COALESCE(contacts.invitations_60d,0) AS invitations_60d,
                COALESCE(inv.pending_invitations,0) AS pending_invitations,
                COALESCE(inv.future_invitations,0) AS future_invitations,
                COALESCE(msg.event_messages_30d,0) AS event_messages_30d,
                COALESCE(rew.rewards_issued_180d,0) AS rewards_issued_180d,
                COALESCE(rew.rewards_claimed_180d,0) AS rewards_claimed_180d
         FROM users u
         JOIN (
             SELECT user_id,COUNT(*) AS active_groups
             FROM group_memberships
             WHERE membership_status='active' AND group_role<>'guest'
             GROUP BY user_id
         ) gm ON gm.user_id=u.id
         LEFT JOIN (
             SELECT ea.user_id,
                    SUM(CASE WHEN e.status='completed' AND ea.status IN ('checked_in','attended','left_early') AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) THEN 1 ELSE 0 END) AS verified_365d,
                    SUM(CASE WHEN e.status='completed' AND ea.status IN ('checked_in','attended','left_early') AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS verified_90d,
                    SUM(CASE WHEN e.status='completed' AND ea.status IN ('checked_in','attended','left_early') AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS verified_30d,
                    MAX(CASE WHEN e.status='completed' AND ea.status IN ('checked_in','attended','left_early') THEN e.starts_at END) AS last_verified_at,
                    SUM(CASE WHEN e.status='completed' AND ea.status IN ('checked_in','attended','left_early') AND e.capacity IS NOT NULL AND e.capacity<=18 AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) THEN 1 ELSE 0 END) AS small_format_365d,
                    SUM(CASE WHEN ea.status='no_show' AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS no_shows_180d
             FROM event_attendance ea JOIN events e ON e.id=ea.event_id
             GROUP BY ea.user_id
         ) att ON att.user_id=u.id
         LEFT JOIN (
             SELECT er.user_id,
                    SUM(CASE WHEN er.response='declined' AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS declines_180d
             FROM event_rsvps er JOIN events e ON e.id=er.event_id
             GROUP BY er.user_id
         ) rsp ON rsp.user_id=u.id
         LEFT JOIN (
             SELECT cycles.user_id,
                    SUM(cycles.contact_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS invitations_30d,
                    SUM(cycles.contact_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 DAY)) AS invitations_60d
             FROM (
                 SELECT ei.user_id,ae.created_at AS contact_at
                 FROM audit_events ae
                 JOIN event_invitations ei
                   ON JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json,'$.invitation_id'))=ei.public_id
                 JOIN events e ON e.id=ei.event_id AND ae.entity_id=e.public_id
                 WHERE ae.event_type='event.user_invited' AND ae.entity_type='event'
                 UNION ALL
                 SELECT ei.user_id,ei.created_at AS contact_at
                 FROM event_invitations ei
                 WHERE NOT EXISTS (
                     SELECT 1 FROM audit_events ae
                     WHERE ae.event_type='event.user_invited'
                       AND ae.entity_type='event'
                       AND JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json,'$.invitation_id'))=ei.public_id
                 )
             ) cycles
             GROUP BY cycles.user_id
         ) contacts ON contacts.user_id=u.id
         LEFT JOIN (
             SELECT ei.user_id,
                    SUM(CASE WHEN ei.status='pending' AND e.starts_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS pending_invitations,
                    SUM(CASE WHEN ei.status<>'revoked' AND e.starts_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS future_invitations
             FROM event_invitations ei JOIN events e ON e.id=ei.event_id
             GROUP BY ei.user_id
         ) inv ON inv.user_id=u.id
         LEFT JOIN (
             SELECT user_id,COUNT(*) AS event_messages_30d
             FROM notifications
             WHERE notification_type LIKE 'event.%' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
             GROUP BY user_id
         ) msg ON msg.user_id=u.id
         LEFT JOIN (
             SELECT user_id,
                    SUM(CASE WHEN issued_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS rewards_issued_180d,
                    SUM(CASE WHEN status='claimed' AND issued_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS rewards_claimed_180d
             FROM reward_issuances
             GROUP BY user_id
         ) rew ON rew.user_id=u.id
         WHERE u.status='active'
         ORDER BY u.id
         LIMIT ".$limit;
    $rows=$pdo->query($sql)->fetchAll();
    foreach($rows as &$row){
        foreach([
            'id','active_groups','verified_365d','verified_90d','verified_30d','small_format_365d',
            'no_shows_180d','declines_180d','invitations_30d','invitations_60d','pending_invitations',
            'future_invitations','event_messages_30d','rewards_issued_180d','rewards_claimed_180d'
        ] as $key)$row[$key]=(int)($row[$key]??0);
    }
    unset($row);
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_journey_admin_index_fast(array $admin,int $limit=100,?PDO $pdo=null): array
{
    $pdo=coveted_member_journey_require_admin($admin,$pdo);
    $rows=[];
    foreach(coveted_member_journey_scan_rows($pdo,max(120,$limit)) as $metrics){
        $decision=coveted_member_journey_decision($metrics);
        $rows[]=[
            'member_ref'=>(string)$metrics['public_id'],'display_name'=>(string)$metrics['display_name'],
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
function coveted_member_journey_agent_context_fast(array $admin,int $limit=60,?PDO $pdo=null): array
{
    if(!coveted_is_system_admin($admin))throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if(coveted_system_sample_mode($admin,$pdo))return ['available'=>false,'reason'=>'sample_mode','summary'=>[],'recommendations'=>[],'attention'=>0];

    $summary=['over_contacted'=>0,'recovery'=>0,'drifting'=>0,'unactivated'=>0,'post_event'=>0,'momentum'=>0,'value_engaged'=>0,'decline_pressure'=>0,'steady'=>0];
    $attention=0;$scanned=0;
    foreach(coveted_member_journey_scan_rows($pdo,max(1,min(80,$limit))) as $metrics){
        $scanned++;
        $decision=coveted_member_journey_decision($metrics);
        $state=(string)$decision['state'];
        $summary[$state]=($summary[$state]??0)+1;
        if((int)$decision['priority']<=2 && $state!=='steady')$attention++;
    }

    $recommendations=[];
    $paced=(int)$summary['over_contacted']+(int)$summary['decline_pressure'];
    if($paced>0){
        $recommendations[]=[
            'priority'=>1,'key'=>'member-journey-pacing','category'=>'Member Journey',
            'title'=>'Reduce member outreach pressure where evidence is elevated',
            'detail'=>'Some active members are already receiving elevated invitation or communication pressure. Review the Member Journey queue before adding more outreach.',
            'evidence'=>$paced.' journey'.($paced===1?'':'s').' currently need pacing review.',
            'href'=>'/admin/member-journeys.php',
        ];
    }

    $reconnect=(int)$summary['recovery']+(int)$summary['drifting'];
    if($reconnect>0){
        $recommendations[]=[
            'priority'=>1,'key'=>'member-journey-reconnect','category'=>'Member Journey',
            'title'=>'Review member reconnection and recovery opportunities',
            'detail'=>'Verified participation history shows members who are drifting or need a lower-pressure recovery path after missed Events.',
            'evidence'=>$reconnect.' journey'.($reconnect===1?'':'s').' currently match reconnection/recovery evidence.',
            'href'=>'/admin/member-journeys.php',
        ];
    }

    $followup=(int)$summary['post_event']+(int)$summary['momentum']+(int)$summary['value_engaged'];
    if($followup>0){
        $recommendations[]=[
            'priority'=>2,'key'=>'member-journey-followup','category'=>'Member Journey',
            'title'=>'Review post-event and positive-momentum member follow-up',
            'detail'=>'Recent verified attendance or proven value engagement indicates relationship follow-up opportunities that should be reviewed before the next Event cycle.',
            'evidence'=>$followup.' journey'.($followup===1?'':'s').' currently match post-event, momentum or value-engagement evidence.',
            'href'=>'/admin/member-journeys.php',
        ];
    }

    $unactivated=(int)$summary['unactivated'];
    if($unactivated>0){
        $recommendations[]=[
            'priority'=>2,'key'=>'member-journey-activation','category'=>'Member Journey',
            'title'=>'Find first-event opportunities for unactivated members',
            'detail'=>'Some active group members have no verified Coveted attendance in the measured year. Review fit before sending another broad invitation.',
            'evidence'=>$unactivated.' active member journey'.($unactivated===1?' has':'s have').' no verified completed-event attendance in the measured year.',
            'href'=>'/admin/member-journeys.php',
        ];
    }

    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));

    return [
        'available'=>true,'scanned'=>$scanned,'summary'=>$summary,'recommendations'=>$recommendations,'attention'=>$attention,
        'privacy'=>'Aggregate Member Journey state/action counts only. Broad Agent context contains no member names, member refs, email addresses, contact details, private messages, personality inference, or public ranking.',
        'authority'=>'Read-only next-best-action intelligence. The Agent may recommend and track work, but System Admin explicitly performs any invitation, communication, reward or relationship action.',
        'performance'=>'One bounded aggregate member scan per Agent context build; no per-member metrics-query loop.',
    ];
}
