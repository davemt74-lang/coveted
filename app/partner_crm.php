<?php
declare(strict_types=1);

require_once __DIR__ . '/venue_relationships.php';
require_once __DIR__ . '/daily_events.php';
require_once __DIR__ . '/partner_perks.php';

function coveted_partner_crm_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        foreach (['business_profiles','partner_relationship_crm','partner_contacts','partner_notes','partner_interactions','partner_followups'] as $table) {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) return false;
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed> */
function coveted_partner_crm_relationship(array $actor, int $businessId, string $groupRef, string $locationRef): array
{
    $resolved = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);
    foreach (coveted_venue_relationships_for_business($actor, $businessId) as $row) {
        if ((int)$row['group_id'] === (int)$resolved['group_id'] && (int)$row['location_id'] === (int)$resolved['location_id']) {
            return $row + $resolved;
        }
    }
    throw new InvalidArgumentException('Partner relationship is not available.');
}

/** @return array<string,mixed> */
function coveted_partner_business_profile(array $actor, int $businessId): array
{
    if (!coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('Business access is required.');
    }
    $stmt = coveted_db()->prepare(
        "SELECT b.id,b.public_id,b.name,b.description,b.status,
                bp.logo_url,bp.cover_url,bp.website_url,bp.phone,bp.category_label,bp.updated_at AS profile_updated_at
         FROM businesses b
         LEFT JOIN business_profiles bp ON bp.business_id=b.id
         WHERE b.id=? LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $row = $stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Business not found.');
    return $row;
}

function coveted_partner_business_profile_update(array $actor, int $businessId, array $data): void
{
    if (!coveted_business_actor_can_manage($actor, $businessId)) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }
    if (!coveted_partner_crm_schema_available()) {
        throw new RuntimeException('Partner Profile CRM database migration is not installed.');
    }

    $logo = coveted_safe_url(trim((string)($data['logo_url'] ?? '')), true);
    $cover = coveted_safe_url(trim((string)($data['cover_url'] ?? '')), true);
    $website = coveted_safe_url(trim((string)($data['website_url'] ?? '')), true);
    $phone = trim((string)($data['phone'] ?? ''));
    $category = trim((string)($data['category_label'] ?? ''));
    if (mb_strlen($phone) > 80 || mb_strlen($category) > 160) {
        throw new InvalidArgumentException('Partner profile field is too long.');
    }

    coveted_db()->prepare(
        "INSERT INTO business_profiles
            (business_id,logo_url,cover_url,website_url,phone,category_label,updated_by_user_id)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            logo_url=VALUES(logo_url),cover_url=VALUES(cover_url),website_url=VALUES(website_url),
            phone=VALUES(phone),category_label=VALUES(category_label),updated_by_user_id=VALUES(updated_by_user_id),updated_at=NOW()"
    )->execute([
        $businessId,$logo ?: null,$cover ?: null,$website ?: null,
        $phone !== '' ? $phone : null,$category !== '' ? $category : null,(int)$actor['id'],
    ]);

    coveted_audit('partner_profile.identity_updated','business',(string)$businessId,[
        'fields' => ['logo_url','cover_url','website_url','phone','category_label'],
    ],(int)$actor['id']);
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_crm_admin_users(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    return $pdo->query(
        "SELECT DISTINCT u.id,u.public_id,u.display_name
         FROM users u
         JOIN user_roles ur ON ur.user_id=u.id AND ur.role_key='system_admin'
         WHERE u.status='active'
         ORDER BY u.display_name,u.id"
    )->fetchAll();
}

/** @return array<string,mixed> */
function coveted_partner_crm_state(array $admin, int $businessId, string $groupRef, string $locationRef): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $rel = coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    if (!coveted_partner_crm_schema_available()) {
        return ['public_id'=>'','relationship_owner_user_id'=>null,'owner_name'=>'','relationship_summary'=>''];
    }
    $stmt = coveted_db()->prepare(
        "SELECT prc.*,COALESCE(u.display_name,'') AS owner_name
         FROM partner_relationship_crm prc
         LEFT JOIN users u ON u.id=prc.relationship_owner_user_id
         WHERE prc.business_id=? AND prc.group_id=? AND prc.location_id=? LIMIT 1"
    );
    $stmt->execute([$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
    return $stmt->fetch() ?: ['public_id'=>'','relationship_owner_user_id'=>null,'owner_name'=>'','relationship_summary'=>''];
}

function coveted_partner_crm_save_state(array $admin, int $businessId, string $groupRef, string $locationRef, array $data): void
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if (!coveted_partner_crm_schema_available()) throw new RuntimeException('Partner Profile CRM database migration is not installed.');
    $rel = coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $owner = isset($data['relationship_owner_user_id']) && (int)$data['relationship_owner_user_id'] > 0
        ? (int)$data['relationship_owner_user_id'] : null;
    $summary = trim((string)($data['relationship_summary'] ?? ''));
    if (mb_strlen($summary) > 4000) throw new InvalidArgumentException('Relationship summary is too long.');
    if ($owner !== null) {
        $stmt = coveted_db()->prepare(
            "SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id AND ur.role_key='system_admin'
             WHERE u.id=? AND u.status='active' LIMIT 1"
        );
        $stmt->execute([$owner]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Relationship owner must be an active System Admin.');
    }

    coveted_db()->prepare(
        "INSERT INTO partner_relationship_crm
            (public_id,business_id,group_id,location_id,relationship_owner_user_id,relationship_summary,created_by_user_id)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            business_id=VALUES(business_id),relationship_owner_user_id=VALUES(relationship_owner_user_id),
            relationship_summary=VALUES(relationship_summary),updated_at=NOW()"
    )->execute([
        coveted_uuid('prc'),$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$owner,
        $summary !== '' ? $summary : null,(int)$admin['id'],
    ]);
    coveted_audit('partner_crm.relationship_updated','venue_relationship',
        (string)$rel['group_public_id'].':'.(string)$rel['location_public_id'],[
            'business_id'=>$businessId,'group_id'=>(int)$rel['group_id'],'location_id'=>(int)$rel['location_id'],
            'relationship_owner_user_id'=>$owner,
        ],(int)$admin['id']);
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_contacts(array $admin, int $businessId, string $groupRef, string $locationRef): array
{
    if (!coveted_is_system_admin($admin)) return [];
    if (!coveted_partner_crm_schema_available()) return [];
    $rel = coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $stmt = coveted_db()->prepare(
        "SELECT * FROM partner_contacts
         WHERE business_id=? AND group_id=? AND location_id=? AND status<>'archived'
         ORDER BY is_primary DESC,FIELD(status,'active','inactive'),full_name,id"
    );
    $stmt->execute([$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
    return $stmt->fetchAll();
}

function coveted_partner_contact_save(array $admin, int $businessId, string $groupRef, string $locationRef, array $data): string
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if (!coveted_partner_crm_schema_available()) throw new RuntimeException('Partner Profile CRM database migration is not installed.');
    $rel = coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $ref = trim((string)($data['contact_ref'] ?? ''));
    $name = trim((string)($data['full_name'] ?? ''));
    $role = trim((string)($data['role_title'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = trim((string)($data['phone'] ?? ''));
    $preferred = strtolower(trim((string)($data['preferred_contact'] ?? 'email')));
    $primary = !empty($data['is_primary']) ? 1 : 0;
    $status = strtolower(trim((string)($data['status'] ?? 'active')));
    if ($name === '' || mb_strlen($name) > 180) throw new InvalidArgumentException('Enter a contact name.');
    if (mb_strlen($role) > 180 || mb_strlen($phone) > 80) throw new InvalidArgumentException('Contact field is too long.');
    if ($email !== '' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid contact email.');
    if (!in_array($preferred,['email','phone','text','in_person','other'],true)) throw new InvalidArgumentException('Choose a valid preferred contact method.');
    if (!in_array($status,['active','inactive','archived'],true)) throw new InvalidArgumentException('Choose a valid contact status.');

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        if ($primary) {
            $pdo->prepare('UPDATE partner_contacts SET is_primary=0 WHERE business_id=? AND group_id=? AND location_id=?')
                ->execute([$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
        }
        if ($ref !== '') {
            $stmt = $pdo->prepare(
                "UPDATE partner_contacts SET full_name=?,role_title=?,email=?,phone=?,preferred_contact=?,is_primary=?,status=?,updated_at=NOW()
                 WHERE (public_id=? OR CAST(id AS CHAR)=?) AND business_id=? AND group_id=? AND location_id=?"
            );
            $stmt->execute([$name,$role?:null,$email?:null,$phone?:null,$preferred,$primary,$status,$ref,$ref,$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
            if ($stmt->rowCount() < 1) {
                $check = $pdo->prepare('SELECT public_id FROM partner_contacts WHERE public_id=? OR CAST(id AS CHAR)=? LIMIT 1');
                $check->execute([$ref,$ref]);
                if (!$check->fetchColumn()) throw new InvalidArgumentException('Partner contact not found.');
            }
            $publicId = $ref;
            $event = 'partner_crm.contact_updated';
        } else {
            $publicId = coveted_uuid('pct');
            $pdo->prepare(
                "INSERT INTO partner_contacts
                    (public_id,business_id,group_id,location_id,full_name,role_title,email,phone,preferred_contact,is_primary,status,created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$publicId,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$name,$role?:null,$email?:null,$phone?:null,$preferred,$primary,$status,(int)$admin['id']]);
            $event = 'partner_crm.contact_created';
        }
        coveted_audit($event,'partner_contact',$publicId,[
            'business_id'=>$businessId,'group_id'=>(int)$rel['group_id'],'location_id'=>(int)$rel['location_id'],
            'contact_name'=>$name,'role_title'=>$role,'preferred_contact'=>$preferred,'is_primary'=>(bool)$primary,'status'=>$status,
        ],(int)$admin['id']);
        $pdo->commit();
        return $publicId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array<string,mixed>|null */
function coveted_partner_contact_by_ref(array $admin, int $businessId, int $groupId, int $locationId, string $ref): ?array
{
    if (!coveted_is_system_admin($admin) || $ref==='') return null;
    $stmt = coveted_db()->prepare(
        'SELECT * FROM partner_contacts WHERE (public_id=? OR CAST(id AS CHAR)=?) AND business_id=? AND group_id=? AND location_id=? LIMIT 1'
    );
    $stmt->execute([$ref,$ref,$businessId,$groupId,$locationId]);
    return $stmt->fetch() ?: null;
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_notes(array $admin, int $businessId, string $groupRef, string $locationRef, int $limit=30): array
{
    if (!coveted_is_system_admin($admin) || !coveted_partner_crm_schema_available()) return [];
    $rel = coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $limit=max(1,min(100,$limit));
    $stmt=coveted_db()->prepare(
        "SELECT pn.*,pc.full_name AS contact_name,u.display_name AS author_name
         FROM partner_notes pn LEFT JOIN partner_contacts pc ON pc.id=pn.contact_id
         JOIN users u ON u.id=pn.created_by_user_id
         WHERE pn.business_id=? AND pn.group_id=? AND pn.location_id=?
         ORDER BY pn.created_at DESC,pn.id DESC LIMIT {$limit}"
    );
    $stmt->execute([$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
    return $stmt->fetchAll();
}

function coveted_partner_note_add(array $admin,int $businessId,string $groupRef,string $locationRef,array $data): string
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if (!coveted_partner_crm_schema_available()) throw new RuntimeException('Partner Profile CRM database migration is not installed.');
    $rel=coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $type=strtolower(trim((string)($data['note_type']??'relationship')));
    $body=trim((string)($data['body']??''));
    if (!in_array($type,['relationship','contact','timeline'],true)) throw new InvalidArgumentException('Choose a valid note type.');
    if ($body==='' || mb_strlen($body)>6000) throw new InvalidArgumentException('Enter a note under 6,000 characters.');
    $contactRef=trim((string)($data['contact_ref']??''));
    $contact=$contactRef!==''?coveted_partner_contact_by_ref($admin,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$contactRef):null;
    if ($type==='contact' && !$contact) throw new InvalidArgumentException('Choose a contact for a contact note.');
    $publicId=coveted_uuid('pnt');
    coveted_db()->prepare(
        'INSERT INTO partner_notes (public_id,business_id,group_id,location_id,contact_id,note_type,body,created_by_user_id) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$publicId,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$contact['id']??null,$type,$body,(int)$admin['id']]);
    coveted_audit('partner_crm.note_added','partner_note',$publicId,[
        'business_id'=>$businessId,'group_id'=>(int)$rel['group_id'],'location_id'=>(int)$rel['location_id'],
        'note_type'=>$type,'contact_name'=>(string)($contact['full_name']??''),'summary'=>mb_substr($body,0,180),
    ],(int)$admin['id']);
    return $publicId;
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_interactions(array $admin,int $businessId,string $groupRef,string $locationRef,int $limit=30): array
{
    if (!coveted_is_system_admin($admin) || !coveted_partner_crm_schema_available()) return [];
    $rel=coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $limit=max(1,min(100,$limit));
    $stmt=coveted_db()->prepare(
        "SELECT pi.*,pc.full_name AS contact_name,u.display_name AS author_name
         FROM partner_interactions pi LEFT JOIN partner_contacts pc ON pc.id=pi.contact_id
         JOIN users u ON u.id=pi.created_by_user_id
         WHERE pi.business_id=? AND pi.group_id=? AND pi.location_id=?
         ORDER BY pi.occurred_at DESC,pi.id DESC LIMIT {$limit}"
    );
    $stmt->execute([$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
    return $stmt->fetchAll();
}

function coveted_partner_interaction_add(array $admin,int $businessId,string $groupRef,string $locationRef,array $data): string
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if (!coveted_partner_crm_schema_available()) throw new RuntimeException('Partner Profile CRM database migration is not installed.');
    $rel=coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $type=strtolower(trim((string)($data['interaction_type']??'other')));
    $direction=strtolower(trim((string)($data['direction']??'outbound')));
    $subject=trim((string)($data['subject']??''));
    $summary=trim((string)($data['summary']??''));
    $occurred=trim((string)($data['occurred_at']??''));
    if (!in_array($type,['call','email','text','meeting','in_person','other'],true)) throw new InvalidArgumentException('Choose a valid interaction type.');
    if (!in_array($direction,['outbound','inbound','internal'],true)) throw new InvalidArgumentException('Choose a valid interaction direction.');
    if (mb_strlen($subject)>190 || $summary==='' || mb_strlen($summary)>6000) throw new InvalidArgumentException('Enter an interaction summary under 6,000 characters.');
    $occurred=$occurred!==''?coveted_utc_datetime($occurred)->format('Y-m-d H:i:s'):gmdate('Y-m-d H:i:s');
    $contactRef=trim((string)($data['contact_ref']??''));
    $contact=$contactRef!==''?coveted_partner_contact_by_ref($admin,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$contactRef):null;
    if ($contactRef!=='' && !$contact) throw new InvalidArgumentException('Partner contact not found.');
    $publicId=coveted_uuid('pin');
    coveted_db()->prepare(
        'INSERT INTO partner_interactions (public_id,business_id,group_id,location_id,contact_id,interaction_type,direction,subject,summary,occurred_at,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$publicId,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$contact['id']??null,$type,$direction,$subject?:null,$summary,$occurred,(int)$admin['id']]);
    coveted_audit('partner_crm.interaction_logged','partner_interaction',$publicId,[
        'business_id'=>$businessId,'group_id'=>(int)$rel['group_id'],'location_id'=>(int)$rel['location_id'],
        'interaction_type'=>$type,'direction'=>$direction,'contact_name'=>(string)($contact['full_name']??''),
        'subject'=>$subject,'summary'=>mb_substr($summary,0,180),'occurred_at'=>$occurred,
    ],(int)$admin['id']);
    return $publicId;
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_followups(array $admin,int $businessId,string $groupRef,string $locationRef,int $limit=50): array
{
    if (!coveted_is_system_admin($admin) || !coveted_partner_crm_schema_available()) return [];
    $rel=coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $limit=max(1,min(100,$limit));
    $stmt=coveted_db()->prepare(
        "SELECT pf.*,pc.full_name AS contact_name,COALESCE(u.display_name,'Unassigned') AS assignee_name
         FROM partner_followups pf LEFT JOIN partner_contacts pc ON pc.id=pf.contact_id
         LEFT JOIN users u ON u.id=pf.assigned_user_id
         WHERE pf.business_id=? AND pf.group_id=? AND pf.location_id=?
         ORDER BY FIELD(pf.status,'open','completed','cancelled'),pf.due_at ASC,pf.id DESC LIMIT {$limit}"
    );
    $stmt->execute([$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
    return $stmt->fetchAll();
}

function coveted_partner_followup_add(array $admin,int $businessId,string $groupRef,string $locationRef,array $data): string
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if (!coveted_partner_crm_schema_available()) throw new RuntimeException('Partner Profile CRM database migration is not installed.');
    $rel=coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $title=trim((string)($data['title']??''));
    $detail=trim((string)($data['detail']??''));
    $due=trim((string)($data['due_at']??''));
    $priority=strtolower(trim((string)($data['priority']??'normal')));
    if ($title==='' || mb_strlen($title)>190 || mb_strlen($detail)>4000) throw new InvalidArgumentException('Enter a follow-up title and keep details concise.');
    if ($due==='') throw new InvalidArgumentException('Choose a follow-up due date.');
    if (!in_array($priority,['low','normal','high'],true)) throw new InvalidArgumentException('Choose a valid follow-up priority.');
    $due=coveted_utc_datetime($due)->format('Y-m-d H:i:s');
    $contactRef=trim((string)($data['contact_ref']??''));
    $contact=$contactRef!==''?coveted_partner_contact_by_ref($admin,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$contactRef):null;
    if ($contactRef!=='' && !$contact) throw new InvalidArgumentException('Partner contact not found.');
    $assignee=isset($data['assigned_user_id']) && (int)$data['assigned_user_id']>0?(int)$data['assigned_user_id']:null;
    if ($assignee!==null) {
        $ids=array_map(static fn(array $u):int=>(int)$u['id'],coveted_partner_crm_admin_users());
        if (!in_array($assignee,$ids,true)) throw new InvalidArgumentException('Follow-up assignee must be an active System Admin.');
    }
    $publicId=coveted_uuid('pfu');
    coveted_db()->prepare(
        'INSERT INTO partner_followups (public_id,business_id,group_id,location_id,contact_id,assigned_user_id,title,detail,due_at,priority,status,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,\'open\',?)'
    )->execute([$publicId,$businessId,(int)$rel['group_id'],(int)$rel['location_id'],$contact['id']??null,$assignee,$title,$detail?:null,$due,$priority,(int)$admin['id']]);
    coveted_audit('partner_crm.followup_created','partner_followup',$publicId,[
        'business_id'=>$businessId,'group_id'=>(int)$rel['group_id'],'location_id'=>(int)$rel['location_id'],
        'title'=>$title,'due_at'=>$due,'priority'=>$priority,'contact_name'=>(string)($contact['full_name']??''),'assigned_user_id'=>$assignee,
    ],(int)$admin['id']);
    return $publicId;
}

function coveted_partner_followup_set_status(array $admin,int $businessId,string $groupRef,string $locationRef,string $followupRef,string $status): void
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    if (!in_array($status,['open','completed','cancelled'],true)) throw new InvalidArgumentException('Invalid follow-up status.');
    $rel=coveted_partner_crm_relationship($admin,$businessId,$groupRef,$locationRef);
    $stmt=coveted_db()->prepare(
        "SELECT * FROM partner_followups WHERE (public_id=? OR CAST(id AS CHAR)=?) AND business_id=? AND group_id=? AND location_id=? LIMIT 1"
    );
    $stmt->execute([$followupRef,$followupRef,$businessId,(int)$rel['group_id'],(int)$rel['location_id']]);
    $row=$stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Partner follow-up not found.');
    coveted_db()->prepare('UPDATE partner_followups SET status=?,completed_at=?,updated_at=NOW() WHERE id=?')
        ->execute([$status,$status==='completed'?gmdate('Y-m-d H:i:s'):null,(int)$row['id']]);
    coveted_audit('partner_crm.followup_status_changed','partner_followup',(string)$row['public_id'],[
        'status'=>$status,'title'=>(string)$row['title'],'business_id'=>$businessId,'group_id'=>(int)$rel['group_id'],'location_id'=>(int)$rel['location_id'],
    ],(int)$admin['id']);
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_profile_timeline(array $actor,int $businessId,string $groupRef,string $locationRef,int $limit=100): array
{
    $rel=coveted_partner_crm_relationship($actor,$businessId,$groupRef,$locationRef);
    $items=[];
    $events=coveted_venue_relationship_events($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id'],50);
    foreach ($events as $event) {
        $items[]=[
            'at'=>(string)$event['starts_at'],'category'=>'event','title'=>(string)$event['title'],
            'detail'=>ucfirst((string)$event['status']).' · '.(int)$event['verified_attendance'].' verified attendance · '.(int)$event['business_benefits_issued'].' benefits · '.(int)$event['claims'].' claims',
            'ref'=>(string)$event['public_id'],
        ];
    }

    try {
        $daily=coveted_daily_event_business_rows($actor,$businessId);
        $eventRefs=array_fill_keys(array_map(static fn(array $e):string=>(string)$e['public_id'],$events),true);
        foreach ($daily as $row) {
            if (!isset($eventRefs[(string)($row['event_ref']??'')])) continue;
            if (!empty($row['reward_unlocked_at'])) {
                $items[]=['at'=>(string)$row['reward_unlocked_at'],'category'=>'daily_event','title'=>'Daily Event threshold unlocked',
                    'detail'=>(int)$row['verified_attendance'].' verified · '.(int)$row['rewards_issued'].' group rewards issued · '.(int)$row['loyalty_points'].' Loyalty pts/member',
                    'ref'=>(string)$row['public_id']];
            }
        }
    } catch (Throwable) {}

    if (coveted_partner_perks_schema_available()) {
        try {
            foreach (coveted_partner_perks_for_relationship($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']) as $perk) {
                $items[]=['at'=>(string)$perk['created_at'],'category'=>'perk','title'=>'Partner Perk · '.(string)$perk['title'],
                    'detail'=>ucfirst(str_replace('_',' ',(string)$perk['status'])).' · '.(int)$perk['issued_count'].' issued · '.(int)$perk['claimed_count'].' claimed',
                    'ref'=>(string)$perk['public_id']];
            }
        } catch (Throwable) {}
    }

    if (coveted_is_system_admin($actor) && coveted_partner_crm_schema_available()) {
        foreach (coveted_partner_contacts($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']) as $contact) {
            $items[]=['at'=>(string)$contact['created_at'],'category'=>'contact','title'=>'Partner contact added · '.(string)$contact['full_name'],
                'detail'=>trim((string)$contact['role_title']) ?: 'Partner contact','ref'=>(string)$contact['public_id']];
        }
        foreach (coveted_partner_notes($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id'],40) as $note) {
            $items[]=['at'=>(string)$note['created_at'],'category'=>'note','title'=>ucfirst((string)$note['note_type']).' note added',
                'detail'=>mb_substr((string)$note['body'],0,220),'ref'=>(string)$note['public_id']];
        }
        foreach (coveted_partner_interactions($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id'],40) as $interaction) {
            $contact=trim((string)($interaction['contact_name']??''));
            $items[]=['at'=>(string)$interaction['occurred_at'],'category'=>'interaction',
                'title'=>ucfirst(str_replace('_',' ',(string)$interaction['interaction_type'])).($contact!==''?' · '.$contact:''),
                'detail'=>trim((string)($interaction['subject']??''))!==''?(string)$interaction['subject'].' — '.mb_substr((string)$interaction['summary'],0,220):mb_substr((string)$interaction['summary'],0,220),
                'ref'=>(string)$interaction['public_id']];
        }
        foreach (coveted_partner_followups($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id'],60) as $followup) {
            $items[]=['at'=>(string)($followup['completed_at'] ?: $followup['created_at']),'category'=>'followup',
                'title'=>((string)$followup['status']==='completed'?'Follow-up completed · ':'Follow-up created · ').(string)$followup['title'],
                'detail'=>'Due '.(string)$followup['due_at'].' · '.ucfirst((string)$followup['priority']).' priority · '.(string)$followup['assignee_name'],
                'ref'=>(string)$followup['public_id']];
        }

        $entity=(string)$rel['group_public_id'].':'.(string)$rel['location_public_id'];
        $stmt=coveted_db()->prepare(
            "SELECT ae.event_type,ae.created_at,COALESCE(u.display_name,'System') AS actor_name
             FROM audit_events ae LEFT JOIN users u ON u.id=ae.actor_user_id
             WHERE ae.entity_type='venue_relationship' AND ae.entity_id=? ORDER BY ae.created_at DESC LIMIT 30"
        );
        $stmt->execute([$entity]);
        foreach ($stmt->fetchAll() as $audit) {
            $items[]=['at'=>(string)$audit['created_at'],'category'=>'relationship','title'=>'Relationship updated',
                'detail'=>(string)$audit['event_type'].' · '.(string)$audit['actor_name'],'ref'=>$entity];
        }
    }

    usort($items,static fn(array $a,array $b):int=>strcmp((string)$b['at'],(string)$a['at']));
    return array_slice($items,0,max(1,min(200,$limit)));
}

/** @return array<string,mixed> */
function coveted_partner_profile_snapshot(array $actor,int $businessId,string $groupRef,string $locationRef): array
{
    $rel=coveted_partner_crm_relationship($actor,$businessId,$groupRef,$locationRef);
    $business=coveted_partner_crm_schema_available()?coveted_partner_business_profile($actor,$businessId):(
        coveted_business_resolve_context($actor,(string)$businessId) ?: []
    );
    $events=coveted_venue_relationship_events($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id'],50);
    $daily=[];
    try {
        $eventRefs=array_fill_keys(array_map(static fn(array $e):string=>(string)$e['public_id'],$events),true);
        foreach (coveted_daily_event_business_rows($actor,$businessId) as $row) {
            if (isset($eventRefs[(string)($row['event_ref']??'')])) $daily[]=$row;
        }
    } catch (Throwable) {}
    $perks=[];
    if (coveted_partner_perks_schema_available()) {
        try {$perks=coveted_partner_perks_for_relationship($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']);} catch (Throwable) {}
    }
    return [
        'business'=>$business,'relationship'=>$rel,'events'=>$events,'daily_events'=>$daily,'perks'=>$perks,
        'crm'=>coveted_is_system_admin($actor)?coveted_partner_crm_state($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']):[],
        'contacts'=>coveted_partner_contacts($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']),
        'notes'=>coveted_partner_notes($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']),
        'interactions'=>coveted_partner_interactions($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']),
        'followups'=>coveted_partner_followups($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']),
        'timeline'=>coveted_partner_profile_timeline($actor,$businessId,(string)$rel['group_public_id'],(string)$rel['location_public_id']),
    ];
}

/**
 * Compact Agent context. Raw phone/email values are intentionally excluded from
 * the broad LLM context; the profile remains the authoritative place to view them.
 * @return array<string,mixed>
 */
function coveted_partner_crm_agent_context(array $admin,int $limit=12,?PDO $pdo=null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo??=coveted_db();
    if (!coveted_partner_crm_schema_available($pdo)) {
        return ['unavailable'=>true,'counts'=>['relationships'=>0,'contacts'=>0,'open_followups'=>0,'overdue_followups'=>0],
            'relationships'=>[],'recent_interactions'=>[],'recommendations'=>[],'privacy'=>'Partner CRM migration is not installed.'];
    }

    $limit=max(1,min(20,$limit));
    $relationships=[];$recommendations=[];$totalContacts=0;$openFollowups=0;$overdueFollowups=0;
    foreach (array_slice(coveted_businesses_for_actor($admin),0,40) as $business) {
        $businessId=(int)$business['id'];
        foreach (coveted_venue_relationships_for_business($admin,$businessId) as $rel) {
            if (count($relationships) >= 60) break 2;
            $groupRef=(string)$rel['group_public_id'];$locationRef=(string)$rel['location_public_id'];
            $contacts=coveted_partner_contacts($admin,$businessId,$groupRef,$locationRef);
            $followups=coveted_partner_followups($admin,$businessId,$groupRef,$locationRef,30);
            $crm=coveted_partner_crm_state($admin,$businessId,$groupRef,$locationRef);
            $interactions=coveted_partner_interactions($admin,$businessId,$groupRef,$locationRef,1);
            $activeContacts=array_values(array_filter($contacts,static fn(array $c):bool=>(string)$c['status']==='active'));
            $primary=null;foreach ($activeContacts as $c) {if ((int)$c['is_primary']===1){$primary=$c;break;}}
            $open=array_values(array_filter($followups,static fn(array $f):bool=>(string)$f['status']==='open'));
            $overdue=array_values(array_filter($open,static fn(array $f):bool=>strtotime((string)$f['due_at'])<time()));
            $dueSoon=array_values(array_filter($open,static fn(array $f):bool=>strtotime((string)$f['due_at'])>=time() && strtotime((string)$f['due_at'])<=time()+604800));
            $totalContacts+=count($activeContacts);$openFollowups+=count($open);$overdueFollowups+=count($overdue);
            $href='/partner-profile.php?business='.rawurlencode((string)$business['public_id']).'&group='.rawurlencode($groupRef).'&location='.rawurlencode($locationRef);
            $lastInteraction=$interactions[0]??null;
            $relationships[]=[
                'business_ref'=>(string)$business['public_id'],'business_name'=>(string)$business['name'],
                'group_ref'=>$groupRef,'group_name'=>(string)$rel['group_name'],'location_ref'=>$locationRef,'location_name'=>(string)$rel['location_name'],
                'relationship_status'=>(string)$rel['relationship_status'],'owner_name'=>(string)($crm['owner_name']??''),
                'primary_contact'=>$primary?['name'=>(string)$primary['full_name'],'role'=>(string)($primary['role_title']??''),'preferred_contact'=>(string)$primary['preferred_contact']]:null,
                'contact_count'=>count($activeContacts),'open_followups'=>count($open),'overdue_followups'=>count($overdue),
                'next_followup'=>$open?['title'=>(string)$open[0]['title'],'due_at'=>(string)$open[0]['due_at'],'contact_name'=>(string)($open[0]['contact_name']??'')]:null,
                'last_interaction'=>$lastInteraction?['type'=>(string)$lastInteraction['interaction_type'],'contact_name'=>(string)($lastInteraction['contact_name']??''),'summary'=>mb_substr((string)$lastInteraction['summary'],0,180),'at'=>(string)$lastInteraction['occurred_at']]:null,
                'href'=>$href,
            ];
            if ($overdue) {
                $f=$overdue[0];
                $recommendations[]=['priority'=>1,'key'=>'partner-crm-overdue-'.(string)$f['public_id'],'kind'=>'partner_followup_overdue',
                    'title'=>'Follow up with '.((string)($f['contact_name']??'')!==''?(string)$f['contact_name']:(string)$rel['location_name']),
                    'detail'=>(string)$f['title'].'. This relationship follow-up is overdue; review the partner profile and record the outcome instead of letting the relationship go quiet.',
                    'evidence'=>'Due '.(string)$f['due_at'].' · '.ucfirst((string)$f['priority']).' priority · '.(string)$business['name'].' / '.(string)$rel['location_name'].'.',
                    'href'=>$href];
            } elseif ($dueSoon) {
                $f=$dueSoon[0];
                $recommendations[]=['priority'=>2,'key'=>'partner-crm-due-'.(string)$f['public_id'],'kind'=>'partner_followup_due',
                    'title'=>'Upcoming partner follow-up · '.(string)$f['title'],
                    'detail'=>'A scheduled partner follow-up is due within seven days. Open the Partner Profile for the relationship context, primary contact and recent interaction history.',
                    'evidence'=>'Due '.(string)$f['due_at'].' · '.(string)$business['name'].' / '.(string)$rel['location_name'].'.','href'=>$href];
            }
            $established=in_array((string)$rel['relationship_status'],['partner','preferred_partner','home_venue'],true) || (int)$rel['completed_events']>=2;
            if ($established && !$activeContacts) {
                $recommendations[]=['priority'=>2,'key'=>'partner-crm-contact-'.$businessId.'-'.(int)$rel['group_id'].'-'.(int)$rel['location_id'],'kind'=>'partner_contact_missing',
                    'title'=>'Add a primary contact for this partner','detail'=>'The relationship has established event history but no active Partner CRM contact. Add the real owner, manager, event lead or marketing contact so future event and perk work has a human relationship attached.',
                    'evidence'=>(int)$rel['completed_events'].' completed events · '.(int)$rel['verified_visits'].' verified visits · 0 active partner contacts.','href'=>$href];
            }
            if ($established && trim((string)($crm['owner_name']??''))==='') {
                $recommendations[]=['priority'=>3,'key'=>'partner-crm-owner-'.$businessId.'-'.(int)$rel['group_id'].'-'.(int)$rel['location_id'],'kind'=>'partner_owner_missing',
                    'title'=>'Assign a Coveted relationship owner','detail'=>'This established partner relationship has no internal Coveted owner. Assign one System Admin so follow-ups and partner history have clear accountability.',
                    'evidence'=>(string)$business['name'].' / '.(string)$rel['location_name'].' · '.(string)$rel['relationship_status'].'.','href'=>$href];
            }
        }
    }

    usort($recommendations,static function(array $a,array $b):int{$p=(int)$a['priority']<=>(int)$b['priority'];return $p!==0?$p:strcmp((string)$a['key'],(string)$b['key']);});
    usort($relationships,static fn(array $a,array $b):int=>((int)$b['overdue_followups']<=>(int)$a['overdue_followups']) ?: strcmp((string)$a['business_name'],(string)$b['business_name']));

    $recent=[];
    $stmt=$pdo->query(
        "SELECT pi.interaction_type,pi.direction,pi.subject,pi.summary,pi.occurred_at,
                b.public_id AS business_ref,b.name AS business_name,g.public_id AS group_ref,g.name AS group_name,
                l.public_id AS location_ref,l.name AS location_name,COALESCE(pc.full_name,'') AS contact_name
         FROM partner_interactions pi JOIN businesses b ON b.id=pi.business_id JOIN social_groups g ON g.id=pi.group_id
         JOIN locations l ON l.id=pi.location_id LEFT JOIN partner_contacts pc ON pc.id=pi.contact_id
         ORDER BY pi.occurred_at DESC,pi.id DESC LIMIT 8"
    );
    foreach ($stmt->fetchAll() as $row) {
        $recent[]=[
            'business_ref'=>(string)$row['business_ref'],'business_name'=>(string)$row['business_name'],
            'group_ref'=>(string)$row['group_ref'],'group_name'=>(string)$row['group_name'],
            'location_ref'=>(string)$row['location_ref'],'location_name'=>(string)$row['location_name'],
            'contact_name'=>(string)$row['contact_name'],'type'=>(string)$row['interaction_type'],'direction'=>(string)$row['direction'],
            'subject'=>(string)($row['subject']??''),'summary'=>mb_substr((string)$row['summary'],0,180),'at'=>(string)$row['occurred_at'],
        ];
    }

    return [
        'unavailable'=>false,
        'counts'=>['relationships'=>count($relationships),'contacts'=>$totalContacts,'open_followups'=>$openFollowups,'overdue_followups'=>$overdueFollowups],
        'relationships'=>array_slice($relationships,0,$limit),'recent_interactions'=>$recent,
        'recommendations'=>array_slice($recommendations,0,20),
        'privacy'=>'Agent context includes partner contact names, roles, preferred contact method, follow-up state and concise interaction summaries. Raw partner email addresses and phone numbers stay out of the broad LLM context and remain on the authoritative Partner Profile.',
        'action_policy'=>'Partner CRM intelligence is read-only for the Agent. Contacts, notes, interactions, assignments and follow-up state change only through explicit authorized Coveted actions.',
    ];
}
