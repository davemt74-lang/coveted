<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/event_management.php';
require_once __DIR__ . '/event_production.php';
require_once __DIR__ . '/system_sample_data.php';

/** @return array{event:array<string,mixed>,role:string,is_admin:bool} */
function coveted_host_command_require_event(array $actor, string $eventRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $eventRef = trim($eventRef);
    $event = $eventRef !== '' ? coveted_event_by_ref($eventRef) : null;
    if (!$event) throw new InvalidArgumentException('Event not found.');

    $isAdmin = coveted_is_system_admin($actor);
    if ($isAdmin && coveted_system_sample_mode($actor, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode is read-only. Host Command is available only for live canonical events.');
    }
    $role = $isAdmin ? 'system_admin' : (string)(coveted_event_assigned_host_role((int)$event['id'], (int)$actor['id']) ?? '');
    if (!$isAdmin && $role === '') throw new InvalidArgumentException('You are not assigned to this event.');
    if (!$isAdmin && !in_array($role, ['lead','cohost','checkin'], true)) throw new InvalidArgumentException('Host Command access is unavailable for this assignment.');
    return ['event'=>$event,'role'=>$role,'is_admin'=>$isAdmin];
}

/** @return array<int,array<string,mixed>> */
function coveted_host_command_events(array $actor, int $limit = 60, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $limit=max(1,min(100,$limit));
    if (coveted_is_system_admin($actor) && coveted_system_sample_mode($actor,$pdo)) return [];
    $rows=coveted_events_for_user($actor,$limit);
    return array_values(array_filter($rows,static function(array $event)use($actor):bool{
        if (coveted_is_system_admin($actor)) return in_array((string)$event['status'],['draft','published','closed'],true);
        return coveted_event_assigned_host_role((int)$event['id'],(int)$actor['id'])!==null
            && !in_array((string)$event['status'],['cancelled'],true);
    }));
}

function coveted_host_command_can_operate_task(string $role, array $item, int $actorId): bool
{
    if ($role === 'system_admin') return true;
    if ((string)$item['phase'] === 'planning') return false;

    $assignedUserId = (int)($item['assigned_user_id'] ?? 0);
    if ($role === 'lead') return true;
    if ($role === 'cohost') return $assignedUserId === 0 || $assignedUserId === $actorId;

    return $role === 'checkin'
        && $assignedUserId === $actorId
        && in_array((string)$item['item_type'],['guest','staffing','task','checklist','safety','other'],true);
}

/** @return array<string,mixed> */
function coveted_host_command_snapshot(array $actor, string $eventRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $access=coveted_host_command_require_event($actor,$eventRef,$pdo);
    $event=$access['event'];
    $eventId=(int)$event['id'];
    $role=(string)$access['role'];

    $locationStmt=$pdo->prepare(
        "SELECT el.location_id,el.private_location_label,el.reveal_notes,l.name AS location_name,l.address1,l.city,l.region,l.postal_code,l.timezone,b.name AS business_name
         FROM event_locations el
         LEFT JOIN locations l ON l.id=el.location_id
         LEFT JOIN businesses b ON b.id=l.business_id
         WHERE el.event_id=? LIMIT 1"
    );
    $locationStmt->execute([$eventId]);
    $location=$locationStmt->fetch() ?: null;

    $hostsStmt=$pdo->prepare(
        "SELECT eh.user_id,eh.host_role,u.display_name
         FROM event_hosts eh JOIN users u ON u.id=eh.user_id
         WHERE eh.event_id=? ORDER BY FIELD(eh.host_role,'lead','cohost','checkin'),u.display_name,u.id"
    );
    $hostsStmt->execute([$eventId]);
    $hosts=$hostsStmt->fetchAll();

    $peopleStmt=$pdo->prepare(
        "SELECT u.id,u.public_id,u.display_name,er.response,er.guest_count,ei.status AS invitation_status,ea.status AS attendance_status,ea.checked_in_at
         FROM users u
         LEFT JOIN event_rsvps er ON er.user_id=u.id AND er.event_id=?
         LEFT JOIN event_invitations ei ON ei.user_id=u.id AND ei.event_id=?
         LEFT JOIN event_attendance ea ON ea.user_id=u.id AND ea.event_id=?
         WHERE er.id IS NOT NULL OR ei.id IS NOT NULL OR ea.id IS NOT NULL
         ORDER BY FIELD(COALESCE(ea.status,''),'checked_in','attended','left_early','no_show',''),FIELD(COALESCE(er.response,''),'attending','waitlist','declined',''),u.display_name,u.id"
    );
    $peopleStmt->execute([$eventId,$eventId,$eventId]);
    $people=$peopleStmt->fetchAll();

    $items=[];$notes=[];
    if (coveted_event_production_schema_available($pdo)) {
        $itemStmt=$pdo->prepare(
            "SELECT epi.*,u.display_name AS assigned_name
             FROM event_production_items epi LEFT JOIN users u ON u.id=epi.assigned_user_id
             WHERE epi.event_id=? AND epi.status<>'cancelled'
             ORDER BY FIELD(epi.phase,'planning','pre_event','arrival','live','closeout'),epi.sort_order,epi.id"
        );
        $itemStmt->execute([$eventId]);
        $items=$itemStmt->fetchAll();
        foreach($items as &$item){
            $item['can_operate']=coveted_host_command_can_operate_task($role,$item,(int)$actor['id']);
            $item['mine']=(int)($item['assigned_user_id']??0)===(int)$actor['id'];
        }
        unset($item);
        $noteStmt=$pdo->prepare(
            "SELECT epn.public_id,epn.note_type,epn.body,epn.created_at,u.display_name AS author_name
             FROM event_production_notes epn JOIN users u ON u.id=epn.created_by_user_id
             WHERE epn.event_id=? AND epn.note_type IN ('run_of_show','incident','closeout')
             ORDER BY epn.created_at DESC,epn.id DESC LIMIT 40"
        );
        $noteStmt->execute([$eventId]);
        $notes=$noteStmt->fetchAll();
    }

    $attending=count(array_filter($people,static fn(array $p):bool=>(string)($p['response']??'')==='attending'));
    $waitlist=count(array_filter($people,static fn(array $p):bool=>(string)($p['response']??'')==='waitlist'));
    $arrived=count(array_filter($people,static fn(array $p):bool=>in_array((string)($p['attendance_status']??''),['checked_in','attended','left_early'],true)));
    $blocked=count(array_filter($items,static fn(array $i):bool=>(string)$i['status']==='blocked'));
    $open=count(array_filter($items,static fn(array $i):bool=>in_array((string)$i['status'],['open','in_progress'],true)));
    $mineOpen=count(array_filter($items,static fn(array $i):bool=>!empty($i['mine'])&&in_array((string)$i['status'],['open','in_progress','blocked'],true)));
    $incidents=count(array_filter($notes,static fn(array $n):bool=>(string)$n['note_type']==='incident'));

    return [
        'event'=>$event,'role'=>$role,'is_admin'=>(bool)$access['is_admin'],'location'=>$location,'hosts'=>$hosts,'people'=>$people,'items'=>$items,'notes'=>$notes,
        'metrics'=>['attending'=>$attending,'waitlist'=>$waitlist,'arrived'=>$arrived,'open_tasks'=>$open,'blocked_tasks'=>$blocked,'my_open_tasks'=>$mineOpen,'incidents'=>$incidents],
        'permissions'=>[
            'attendance'=>coveted_event_can_checkin($event,$actor) && !in_array((string)$event['status'],['draft','cancelled'],true),
            'operational_tasks'=>$role==='system_admin'||in_array($role,['lead','cohost','checkin'],true),
            'incident_notes'=>$role==='system_admin'||in_array($role,['lead','cohost','checkin'],true),
            'event_configuration'=>(bool)$access['is_admin'],
        ],
    ];
}

function coveted_host_command_update_task(array $actor,string $eventRef,string $itemRef,string $status,?PDO $pdo=null): void
{
    $pdo ??= coveted_db();
    $access=coveted_host_command_require_event($actor,$eventRef,$pdo);
    if (!coveted_event_production_schema_available($pdo)) throw new RuntimeException('Event Production is not installed.');
    $status=strtolower(trim($status));
    if (!in_array($status,['open','in_progress','blocked','completed'],true)) throw new InvalidArgumentException('Choose a valid task status.');
    $stmt=$pdo->prepare('SELECT * FROM event_production_items WHERE event_id=? AND public_id=? LIMIT 1');
    $stmt->execute([(int)$access['event']['id'],trim($itemRef)]);
    $item=$stmt->fetch();
    if (!$item) throw new InvalidArgumentException('Production task not found.');
    if (!coveted_host_command_can_operate_task((string)$access['role'],$item,(int)$actor['id'])) throw new InvalidArgumentException('This production task is not available to your host role.');
    $completed=$status==='completed'?gmdate('Y-m-d H:i:s'):null;
    $pdo->prepare('UPDATE event_production_items SET status=?,completed_at=?,updated_at=NOW() WHERE id=?')->execute([$status,$completed,(int)$item['id']]);
    coveted_audit('event.host_task_updated','event',(string)$access['event']['public_id'],['item_ref'=>(string)$item['public_id'],'phase'=>(string)$item['phase'],'status'=>$status,'host_role'=>(string)$access['role']],(int)$actor['id']);
}

function coveted_host_command_add_note(array $actor,string $eventRef,string $type,string $body,?PDO $pdo=null): string
{
    $pdo ??= coveted_db();
    $access=coveted_host_command_require_event($actor,$eventRef,$pdo);
    if (!coveted_event_production_schema_available($pdo)) throw new RuntimeException('Event Production is not installed.');
    $type=strtolower(trim($type));$body=trim($body);
    if (!in_array($type,['incident','closeout'],true)) throw new InvalidArgumentException('Hosts may add only incident or closeout notes.');
    if ($body===''||mb_strlen($body)>6000) throw new InvalidArgumentException('Enter an operational note under 6,000 characters.');
    if ($type==='closeout' && !in_array((string)$access['role'],['lead','cohost','system_admin'],true)) throw new InvalidArgumentException('Lead or cohost access is required for closeout notes.');
    $publicId=coveted_uuid('pnote');
    $pdo->prepare('INSERT INTO event_production_notes (public_id,event_id,note_type,body,created_by_user_id) VALUES (?,?,?,?,?)')->execute([$publicId,(int)$access['event']['id'],$type,$body,(int)$actor['id']]);
    coveted_audit('event.host_note_added','event',(string)$access['event']['public_id'],['note_ref'=>$publicId,'note_type'=>$type,'host_role'=>(string)$access['role']],(int)$actor['id']);
    return $publicId;
}

function coveted_host_command_record_attendance(array $actor,string $eventRef,int $userId,string $status): void
{
    $access=coveted_host_command_require_event($actor,$eventRef);
    if (!coveted_event_can_checkin($access['event'],$actor)) throw new InvalidArgumentException('Check-in access is required.');
    coveted_event_record_attendance($actor,$eventRef,$userId,$status);
}

/** @return array<string,mixed> */
function coveted_host_command_agent_context(array $admin,?PDO $pdo=null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo) || !coveted_event_production_schema_available($pdo)) return ['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0];
    $rows=$pdo->query(
        "SELECT e.id,e.public_id,e.title,e.status,e.starts_at,g.name AS group_name,
                (SELECT COUNT(*) FROM event_hosts eh WHERE eh.event_id=e.id) AS host_count,
                (SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=e.id AND epi.status='blocked' AND epi.phase<>'planning') AS host_blocked,
                (SELECT COUNT(*) FROM event_production_items epi WHERE epi.event_id=e.id AND epi.status IN ('open','in_progress') AND epi.phase IN ('pre_event','arrival','live','closeout')) AS host_open,
                (SELECT COUNT(*) FROM event_production_notes epn WHERE epn.event_id=e.id AND epn.note_type='incident' AND epn.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)) AS incidents_24h,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id=e.id AND er.response='attending') AS attending,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id=e.id AND ea.status IN ('checked_in','attended','left_early')) AS arrived
         FROM events e JOIN social_groups g ON g.id=e.group_id
         WHERE e.status IN ('published','closed') AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND e.starts_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 14 DAY)
         ORDER BY e.starts_at ASC LIMIT 20"
    )->fetchAll();
    $events=[];$recommendations=[];$attention=0;
    foreach($rows as $row){
        $blocked=(int)$row['host_blocked'];
        $incidents=(int)$row['incidents_24h'];
        $needs=$blocked>0||$incidents>0;
        if($needs)$attention++;
        $href='/host-command.php?event='.rawurlencode((string)$row['public_id']);
        $events[]=[
            'event_ref'=>(string)$row['public_id'],'title'=>(string)$row['title'],'status'=>(string)$row['status'],'starts_at'=>(string)$row['starts_at'],'group'=>(string)$row['group_name'],
            'hosts'=>(int)$row['host_count'],'open_host_tasks'=>(int)$row['host_open'],'blocked_host_tasks'=>$blocked,'incidents_24h'=>$incidents,'attending'=>(int)$row['attending'],'arrived'=>(int)$row['arrived'],
            'href'=>$href,
        ];
        if($blocked>0){
            $recommendations[]=['priority'=>1,'key'=>'host-blocked:'.$row['public_id'],'category'=>'Events','title'=>'Resolve blocked Host Command work · '.$row['title'],'detail'=>'The assigned host team has blocked operational Production work. Review the canonical task state and unblock the event-day plan.','href'=>$href,'evidence'=>$blocked.' blocked host task'.($blocked===1?'':'s').' · '.$row['group_name']];
        }
        if($incidents>0){
            $recommendations[]=['priority'=>1,'key'=>'host-incident:'.$row['public_id'],'category'=>'Events','title'=>'Review Host Command incident · '.$row['title'],'detail'=>'The host team logged a meaningful event-day incident in the last 24 hours. Review the operational record and decide whether follow-up is required.','href'=>$href,'evidence'=>$incidents.' incident'.($incidents===1?'':'s').' in 24h · '.$row['group_name']];
        }
    }
    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return ['available'=>true,'events'=>$events,'recommendations'=>array_slice($recommendations,0,12),'attention'=>$attention,'authority'=>'Host Command is operational only. Hosts may execute allowed Production tasks, check in eligible guests and log incidents/closeout; event configuration remains System Admin-only.'];
}
