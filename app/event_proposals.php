<?php
declare(strict_types=1);

require_once __DIR__ . '/event_opportunities.php';
require_once __DIR__ . '/event_production.php';
require_once __DIR__ . '/partner_crm.php';
require_once __DIR__ . '/system_sample_data.php';

function coveted_event_proposal_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        foreach (['event_playbooks','event_proposals','event_proposal_updates'] as $table) {
            $pdo->query('SELECT id FROM ' . $table . ' LIMIT 1');
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<int,mixed> */
function coveted_event_proposal_json_list(mixed $value): array
{
    if (is_array($value)) return array_values($value);
    $raw = trim((string)$value);
    if ($raw === '') return [];
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? array_values($decoded) : [];
    } catch (Throwable) {
        return [];
    }
}

function coveted_event_proposal_lines(string $raw, int $maxItems = 24): string
{
    $items = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
    $items = array_values(array_filter(array_map(static fn(string $v): string => trim($v), $items), static fn(string $v): bool => $v !== ''));
    if (count($items) > $maxItems) throw new InvalidArgumentException('Too many playbook list items.');
    foreach ($items as $item) if (mb_strlen($item) > 320) throw new InvalidArgumentException('A playbook list item is too long.');
    return coveted_json($items);
}

/** @return array<int,array<string,mixed>> */
function coveted_event_playbooks(array $admin, bool $includeArchived = false, ?PDO $pdo = null): array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (!coveted_event_proposal_schema_available($pdo)) return [];
    $sql = 'SELECT * FROM event_playbooks' . ($includeArchived ? '' : " WHERE status='active'") . " ORDER BY status='active' DESC,name,id";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        foreach (['required_host_roles_json','recommended_benefits_json','guest_cadence_json','run_of_show_json','production_items_json','closeout_json'] as $field) {
            $row[$field] = coveted_event_proposal_json_list($row[$field] ?? null);
        }
    }
    unset($row);
    return $rows;
}

/** @return array<string,mixed>|null */
function coveted_event_playbook_by_ref(array $admin, string $ref, ?PDO $pdo = null): ?array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    $ref = trim($ref);
    if ($ref === '' || !coveted_event_proposal_schema_available($pdo)) return null;
    $stmt = $pdo->prepare('SELECT * FROM event_playbooks WHERE public_id=? OR playbook_key=? OR CAST(id AS CHAR)=? LIMIT 1');
    $stmt->execute([$ref,$ref,$ref]);
    $row = $stmt->fetch();
    if (!$row) return null;
    foreach (['required_host_roles_json','recommended_benefits_json','guest_cadence_json','run_of_show_json','production_items_json','closeout_json'] as $field) {
        $row[$field] = coveted_event_proposal_json_list($row[$field] ?? null);
    }
    return $row;
}

function coveted_event_playbook_save(array $admin, array $data, ?PDO $pdo = null): string
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode is read-only.');
    if (!coveted_event_proposal_schema_available($pdo)) throw new RuntimeException('Import the Event Proposals + Playbooks migration first.');

    $ref = trim((string)($data['playbook_ref'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $key = strtolower(trim((string)($data['playbook_key'] ?? '')));
    $description = trim((string)($data['description'] ?? ''));
    $eventType = strtolower(trim((string)($data['event_type'] ?? 'regular')));
    $audience = strtolower(trim((string)($data['audience'] ?? 'group')));
    $duration = (int)($data['default_duration_minutes'] ?? 150);
    $capacity = (int)($data['default_capacity'] ?? 20);
    $plusOne = !empty($data['plus_one_allowed']) ? 1 : 0;
    $visibility = strtolower(trim((string)($data['location_visibility'] ?? 'immediate')));
    $status = strtolower(trim((string)($data['status'] ?? 'active')));

    if ($name === '' || mb_strlen($name) > 190) throw new InvalidArgumentException('Enter a playbook name.');
    if ($key === '') $key = trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower($name)),'_');
    if (!preg_match('/^[a-z0-9_]{2,80}$/',$key)) throw new InvalidArgumentException('Playbook key may contain lowercase letters, numbers and underscores.');
    if (mb_strlen($description) > 5000) throw new InvalidArgumentException('Playbook description is too long.');
    if (!in_array($eventType,['regular','mystery','private_table','member_plus_one','session'],true)) throw new InvalidArgumentException('Choose a valid event type.');
    if (!in_array($audience,['group','invitation_only'],true)) throw new InvalidArgumentException('Choose a valid audience.');
    if (!in_array($visibility,['immediate','scheduled_reveal','host_only'],true)) throw new InvalidArgumentException('Choose a valid location visibility.');
    if (!in_array($status,['active','archived'],true)) throw new InvalidArgumentException('Choose a valid playbook status.');
    if ($duration < 30 || $duration > 720 || $capacity < 1 || $capacity > 1000) throw new InvalidArgumentException('Playbook duration or capacity is outside the allowed range.');

    $lists = [
        coveted_event_proposal_lines((string)($data['required_host_roles'] ?? '')),
        coveted_event_proposal_lines((string)($data['recommended_benefits'] ?? '')),
        coveted_event_proposal_lines((string)($data['guest_cadence'] ?? '')),
        coveted_event_proposal_lines((string)($data['run_of_show'] ?? '')),
        coveted_event_proposal_lines((string)($data['production_items'] ?? '')),
        coveted_event_proposal_lines((string)($data['closeout'] ?? '')),
    ];

    if ($ref !== '') {
        $stmt = $pdo->prepare(
            "UPDATE event_playbooks SET playbook_key=?,name=?,description=?,event_type=?,audience=?,default_duration_minutes=?,default_capacity=?,plus_one_allowed=?,location_visibility=?,required_host_roles_json=?,recommended_benefits_json=?,guest_cadence_json=?,run_of_show_json=?,production_items_json=?,closeout_json=?,status=?,updated_at=NOW() WHERE public_id=? OR CAST(id AS CHAR)=?"
        );
        $stmt->execute([$key,$name,$description?:null,$eventType,$audience,$duration,$capacity,$plusOne,$visibility,$lists[0],$lists[1],$lists[2],$lists[3],$lists[4],$lists[5],$status,$ref,$ref]);
        $check = $pdo->prepare('SELECT public_id FROM event_playbooks WHERE public_id=? OR CAST(id AS CHAR)=? LIMIT 1');
        $check->execute([$ref,$ref]);
        $publicId = (string)($check->fetchColumn() ?: '');
        if ($publicId === '') throw new InvalidArgumentException('Playbook not found.');
        $audit='event.playbook_updated';
    } else {
        $publicId = coveted_uuid('playbook');
        $pdo->prepare(
            "INSERT INTO event_playbooks (public_id,playbook_key,name,description,event_type,audience,default_duration_minutes,default_capacity,plus_one_allowed,location_visibility,required_host_roles_json,recommended_benefits_json,guest_cadence_json,run_of_show_json,production_items_json,closeout_json,status,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([$publicId,$key,$name,$description?:null,$eventType,$audience,$duration,$capacity,$plusOne,$visibility,$lists[0],$lists[1],$lists[2],$lists[3],$lists[4],$lists[5],$status,(int)$admin['id']]);
        $audit='event.playbook_created';
    }
    coveted_audit($audit,'event_playbook',$publicId,['playbook_key'=>$key,'name'=>$name,'status'=>$status],(int)$admin['id']);
    return $publicId;
}

/** @return array<string,mixed>|null */
function coveted_event_proposal_suggest_playbook(array $opportunity, array $playbooks): ?array
{
    $draft=(array)($opportunity['suggested_draft'] ?? []);
    $type=(string)($draft['event_type'] ?? 'regular');
    $title=strtolower((string)($draft['title'] ?? ''));
    $preferred = match (true) {
        $type === 'mystery' => 'mystery_event',
        $type === 'session' && (str_contains($title,'listen') || str_contains($title,'vinyl')) => 'listening_session',
        $type === 'session' => 'wellness_session',
        $type === 'private_table' => 'private_table',
        default => 'supper_club',
    };
    foreach ($playbooks as $playbook) if ((string)$playbook['playbook_key'] === $preferred) return $playbook;
    return $playbooks[0] ?? null;
}

/** @return array<string,mixed> */
function coveted_event_proposal_create_from_opportunity(array $admin, string $opportunityKey, string $playbookRef = '', ?PDO $pdo = null): array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode is read-only.');
    if (!coveted_event_proposal_schema_available($pdo)) throw new RuntimeException('Import the Event Proposals + Playbooks migration first.');
    $opportunity=coveted_event_opportunity_by_key($admin,$opportunityKey,$pdo);
    if (!$opportunity) throw new InvalidArgumentException('That Event Opportunity is no longer available.');
    $draft=(array)$opportunity['suggested_draft'];
    $playbooks=coveted_event_playbooks($admin,false,$pdo);
    $playbook=$playbookRef!=='' ? coveted_event_playbook_by_ref($admin,$playbookRef,$pdo) : coveted_event_proposal_suggest_playbook($opportunity,$playbooks);
    if (!$playbook || (string)$playbook['status']!=='active') throw new InvalidArgumentException('Choose an active Event Playbook.');

    $duplicate=$pdo->prepare("SELECT public_id FROM event_proposals WHERE opportunity_key=? AND status NOT IN ('converted','declined') ORDER BY id DESC LIMIT 1");
    $duplicate->execute([$opportunityKey]);
    $existing=(string)($duplicate->fetchColumn() ?: '');
    if ($existing!=='') return ['public_id'=>$existing,'created'=>false];

    $location=$pdo->prepare("SELECT l.id,l.business_id,l.timezone,b.status AS business_status,l.status AS location_status FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? LIMIT 1");
    $location->execute([(int)$draft['location_id']]);
    $locationRow=$location->fetch();
    if (!$locationRow || $locationRow['location_status']!=='active' || $locationRow['business_status']!=='active') throw new InvalidArgumentException('The recommended partner location is no longer active.');

    $capacity=max(1,(int)($draft['capacity'] ?? $playbook['default_capacity']));
    $expected=min($capacity,max(1,(int)($opportunity['signals']['active_members'] ?? $capacity)));
    $publicId=coveted_uuid('proposal');
    $pdo->prepare(
        "INSERT INTO event_proposals (public_id,opportunity_key,playbook_id,group_id,business_id,location_id,title,concept,status,proposed_start_at,expected_attendance,capacity,internal_notes,created_by_user_id) VALUES (?,?,?,?,?,?,?,?, 'idea',?,?,?,?,?)"
    )->execute([$publicId,$opportunityKey,(int)$playbook['id'],(int)$draft['group_id'],(int)$locationRow['business_id'],(int)$draft['location_id'],(string)$draft['title'],(string)$draft['description'],(string)$draft['starts_at'],$expected,$capacity,'Created from Event Opportunity score '.(int)$opportunity['score'].'.',(int)$admin['id']]);
    coveted_audit('event.proposal_created','event_proposal',$publicId,['opportunity_key'=>$opportunityKey,'playbook_key'=>(string)$playbook['playbook_key'],'score'=>(int)$opportunity['score']],(int)$admin['id']);
    return ['public_id'=>$publicId,'created'=>true];
}

/** @return array<int,array<string,mixed>> */
function coveted_event_proposals(array $admin, string $status = '', ?PDO $pdo = null): array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (!coveted_event_proposal_schema_available($pdo)) return [];
    $status=strtolower(trim($status));
    $where=''; $params=[];
    if ($status!=='' && in_array($status,['idea','reviewing','partner_contacted','negotiating','approved','converted','declined'],true)) { $where='WHERE ep.status=?'; $params[]=$status; }
    $stmt=$pdo->prepare(
        "SELECT ep.*,pb.public_id AS playbook_ref,pb.playbook_key,pb.name AS playbook_name,pb.event_type AS playbook_event_type,pb.default_duration_minutes,pb.plus_one_allowed,pb.location_visibility,
                g.public_id AS group_ref,g.name AS group_name,b.public_id AS business_ref,b.name AS business_name,l.public_id AS location_ref,l.name AS location_name,l.city AS location_city,l.region AS location_region,l.timezone,
                ap.public_id AS artist_ref,ap.artist_name,
                COALESCE((SELECT MAX(epu.created_at) FROM event_proposal_updates epu WHERE epu.proposal_id=ep.id),ep.updated_at) AS last_activity_at,
                (SELECT COUNT(*) FROM event_proposal_updates epu2 WHERE epu2.proposal_id=ep.id) AS update_count
         FROM event_proposals ep
         JOIN event_playbooks pb ON pb.id=ep.playbook_id
         JOIN social_groups g ON g.id=ep.group_id
         JOIN businesses b ON b.id=ep.business_id
         JOIN locations l ON l.id=ep.location_id
         LEFT JOIN artist_profiles ap ON ap.id=ep.artist_id
         {$where}
         ORDER BY FIELD(ep.status,'approved','negotiating','partner_contacted','reviewing','idea','converted','declined'),COALESCE(ep.proposed_start_at,'9999-12-31'),ep.updated_at DESC,ep.id DESC LIMIT 200"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<string,mixed>|null */
function coveted_event_proposal_by_ref(array $admin, string $ref, ?PDO $pdo = null): ?array
{
    $ref=trim($ref);
    if ($ref==='') return null;
    foreach (coveted_event_proposals($admin,'',$pdo) as $row) if ((string)$row['public_id']===$ref || (string)$row['id']===$ref) return $row;
    return null;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_proposal_updates(array $admin, string $proposalRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $proposal=coveted_event_proposal_by_ref($admin,$proposalRef,$pdo);
    if (!$proposal) return [];
    $stmt=$pdo->prepare("SELECT epu.*,u.display_name AS author_name FROM event_proposal_updates epu JOIN users u ON u.id=epu.created_by_user_id WHERE epu.proposal_id=? ORDER BY epu.created_at DESC,epu.id DESC LIMIT 100");
    $stmt->execute([(int)$proposal['id']]);
    return $stmt->fetchAll();
}

function coveted_event_proposal_update(array $admin, string $proposalRef, array $data, ?PDO $pdo = null): void
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode is read-only.');
    $proposal=coveted_event_proposal_by_ref($admin,$proposalRef,$pdo);
    if (!$proposal) throw new InvalidArgumentException('Event Proposal not found.');
    if (in_array((string)$proposal['status'],['converted','declined'],true)) throw new InvalidArgumentException('Converted or declined proposals are read-only.');

    $playbook=coveted_event_playbook_by_ref($admin,(string)($data['playbook_ref'] ?? $proposal['playbook_ref']),$pdo);
    if (!$playbook || (string)$playbook['status']!=='active') throw new InvalidArgumentException('Choose an active Event Playbook.');
    $title=trim((string)($data['title'] ?? $proposal['title']));
    $concept=trim((string)($data['concept'] ?? $proposal['concept']));
    $start=trim((string)($data['proposed_start_at'] ?? $proposal['proposed_start_at']));
    $alternate=trim((string)($data['alternate_start_at'] ?? $proposal['alternate_start_at']));
    $expected=(int)($data['expected_attendance'] ?? $proposal['expected_attendance']);
    $capacity=(int)($data['capacity'] ?? $proposal['capacity']);
    $contactRef=trim((string)($data['partner_contact_ref'] ?? $proposal['partner_contact_ref']));
    $contribution=trim((string)($data['partner_contribution'] ?? $proposal['partner_contribution']));
    $perk=trim((string)($data['proposed_perk'] ?? $proposal['proposed_perk']));
    $requirements=trim((string)($data['special_requirements'] ?? $proposal['special_requirements']));
    $notes=trim((string)($data['internal_notes'] ?? $proposal['internal_notes']));
    $artistId=(int)($data['artist_id'] ?? $proposal['artist_id']);
    if ($title==='' || mb_strlen($title)>190) throw new InvalidArgumentException('Enter a proposal title.');
    foreach ([$concept,$contribution,$perk,$requirements,$notes] as $copy) if (mb_strlen($copy)>6000) throw new InvalidArgumentException('Proposal copy is too long.');
    if ($capacity<1 || $capacity>1000 || $expected<1 || $expected>$capacity) throw new InvalidArgumentException('Expected attendance must fit inside proposal capacity.');
    if ($start!=='') $start=coveted_utc_datetime($start)->format('Y-m-d H:i:s');
    if ($alternate!=='') $alternate=coveted_utc_datetime($alternate)->format('Y-m-d H:i:s');
    if ($contactRef!=='' && coveted_partner_crm_schema_available($pdo)) {
        $contact=coveted_partner_contact_by_ref($admin,(int)$proposal['business_id'],(int)$proposal['group_id'],(int)$proposal['location_id'],$contactRef);
        if (!$contact) throw new InvalidArgumentException('Partner contact does not belong to this relationship.');
    }
    if ($artistId>0) {
        $artist=$pdo->prepare("SELECT 1 FROM artist_profiles WHERE id=? AND status='active' LIMIT 1"); $artist->execute([$artistId]);
        if (!$artist->fetchColumn()) throw new InvalidArgumentException('Choose an active Artist Partner.');
    }
    $pdo->prepare("UPDATE event_proposals SET playbook_id=?,artist_id=?,title=?,concept=?,proposed_start_at=?,alternate_start_at=?,expected_attendance=?,capacity=?,partner_contact_ref=?,partner_contribution=?,proposed_perk=?,special_requirements=?,internal_notes=?,updated_at=NOW() WHERE id=?")
        ->execute([(int)$playbook['id'],$artistId?:null,$title,$concept?:null,$start?:null,$alternate?:null,$expected,$capacity,$contactRef?:null,$contribution?:null,$perk?:null,$requirements?:null,$notes?:null,(int)$proposal['id']]);
    coveted_audit('event.proposal_updated','event_proposal',(string)$proposal['public_id'],['playbook_key'=>(string)$playbook['playbook_key'],'capacity'=>$capacity,'expected_attendance'=>$expected],(int)$admin['id']);
}

function coveted_event_proposal_set_status(array $admin, string $proposalRef, string $status, ?PDO $pdo = null): void
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode is read-only.');
    $proposal=coveted_event_proposal_by_ref($admin,$proposalRef,$pdo);
    if (!$proposal) throw new InvalidArgumentException('Event Proposal not found.');
    $status=strtolower(trim($status));
    if (!in_array($status,['idea','reviewing','partner_contacted','negotiating','declined'],true)) throw new InvalidArgumentException('Choose a valid proposal workflow status. Approval and conversion use their dedicated actions.');
    if ((string)$proposal['status']==='converted') throw new InvalidArgumentException('Converted proposals are read-only.');
    $before=(string)$proposal['status'];
    $pdo->prepare('UPDATE event_proposals SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,(int)$proposal['id']]);
    coveted_event_proposal_add_update_record($pdo,(int)$proposal['id'],'status_change','',$before,$status,'Status changed from '.str_replace('_',' ',$before).' to '.str_replace('_',' ',$status).'.',(int)$admin['id']);
    coveted_audit('event.proposal_status_changed','event_proposal',(string)$proposal['public_id'],['from'=>$before,'to'=>$status],(int)$admin['id']);
}

function coveted_event_proposal_add_update_record(PDO $pdo,int $proposalId,string $type,string $contactRef,string $from,string $to,string $body,int $actorId): string
{
    $publicId=coveted_uuid('pupdate');
    $pdo->prepare('INSERT INTO event_proposal_updates (public_id,proposal_id,update_type,contact_ref,status_from,status_to,body,created_by_user_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$publicId,$proposalId,$type,$contactRef?:null,$from?:null,$to?:null,$body,$actorId]);
    return $publicId;
}

function coveted_event_proposal_add_update(array $admin,string $proposalRef,array $data,?PDO $pdo=null): string
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode is read-only.');
    $proposal=coveted_event_proposal_by_ref($admin,$proposalRef,$pdo);
    if (!$proposal) throw new InvalidArgumentException('Event Proposal not found.');
    if (in_array((string)$proposal['status'],['converted','declined'],true)) throw new InvalidArgumentException('This proposal is read-only.');
    $type=strtolower(trim((string)($data['update_type'] ?? 'internal_note')));
    $body=trim((string)($data['body'] ?? ''));
    $contactRef=trim((string)($data['contact_ref'] ?? $proposal['partner_contact_ref']));
    $interactionType=strtolower(trim((string)($data['interaction_type'] ?? 'email')));
    if (!in_array($type,['internal_note','partner_contact','partner_feedback'],true)) throw new InvalidArgumentException('Choose a valid proposal update type.');
    if ($body==='' || mb_strlen($body)>6000) throw new InvalidArgumentException('Enter a proposal update under 6,000 characters.');
    if (!in_array($interactionType,['call','email','text','meeting','in_person','other'],true)) throw new InvalidArgumentException('Choose a valid contact method.');

    if ($contactRef!=='' && coveted_partner_crm_schema_available($pdo)) {
        $contact=coveted_partner_contact_by_ref($admin,(int)$proposal['business_id'],(int)$proposal['group_id'],(int)$proposal['location_id'],$contactRef);
        if (!$contact) throw new InvalidArgumentException('Partner contact does not belong to this relationship.');
    }
    $ref=coveted_event_proposal_add_update_record($pdo,(int)$proposal['id'],$type,$contactRef,'','',$body,(int)$admin['id']);

    if ($type==='partner_contact' && in_array((string)$proposal['status'],['idea','reviewing'],true)) {
        $pdo->prepare("UPDATE event_proposals SET status='partner_contacted',partner_contact_ref=COALESCE(NULLIF(?,''),partner_contact_ref),updated_at=NOW() WHERE id=?")
            ->execute([$contactRef,(int)$proposal['id']]);
    } elseif ($type==='partner_feedback' && !in_array((string)$proposal['status'],['approved','converted'],true)) {
        $pdo->prepare("UPDATE event_proposals SET status='negotiating',partner_contact_ref=COALESCE(NULLIF(?,''),partner_contact_ref),updated_at=NOW() WHERE id=?")
            ->execute([$contactRef,(int)$proposal['id']]);
    }

    if ($type!=='internal_note' && coveted_partner_crm_schema_available($pdo)) {
        try {
            coveted_partner_interaction_add($admin,(int)$proposal['business_id'],(string)$proposal['group_ref'],(string)$proposal['location_ref'],[
                'interaction_type'=>$interactionType,
                'direction'=>$type==='partner_feedback'?'inbound':'outbound',
                'subject'=>'Event proposal: '.(string)$proposal['title'],
                'summary'=>$body,
                'contact_ref'=>$contactRef,
                'occurred_at'=>gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('Event Proposal Partner CRM mirror skipped: '.$e->getMessage());
        }
    }
    coveted_audit('event.proposal_update_added','event_proposal',(string)$proposal['public_id'],['update_type'=>$type,'contact_ref'=>$contactRef,'summary'=>mb_substr($body,0,180)],(int)$admin['id']);
    return $ref;
}

function coveted_event_proposal_approve(array $admin,string $proposalRef,?PDO $pdo=null): void
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    $proposal=coveted_event_proposal_by_ref($admin,$proposalRef,$pdo);
    if (!$proposal) throw new InvalidArgumentException('Event Proposal not found.');
    if (in_array((string)$proposal['status'],['converted','declined'],true)) throw new InvalidArgumentException('This proposal cannot be approved.');
    if (empty($proposal['proposed_start_at'])) throw new InvalidArgumentException('Choose a proposed date before approval.');
    $pdo->prepare("UPDATE event_proposals SET status='approved',approved_at=NOW(),approved_by_user_id=?,updated_at=NOW() WHERE id=?")
        ->execute([(int)$admin['id'],(int)$proposal['id']]);
    coveted_event_proposal_add_update_record($pdo,(int)$proposal['id'],'approval','',(string)$proposal['status'],'approved','Proposal approved for conversion to a canonical Event.',(int)$admin['id']);
    coveted_audit('event.proposal_approved','event_proposal',(string)$proposal['public_id'],[],(int)$admin['id']);
}

function coveted_event_proposal_seed_playbook(array $admin,array $proposal,array $playbook,string $eventRef,?PDO $pdo=null): void
{
    $pdo ??= coveted_db();
    if (!coveted_event_production_schema_available($pdo)) return;
    coveted_event_production_seed_defaults($admin,$eventRef,$pdo);
    $order=300;
    foreach ((array)$playbook['production_items_json'] as $title) {
        if (!is_string($title) || trim($title)==='') continue;
        coveted_event_production_create_item($admin,$eventRef,['phase'=>'planning','item_type'=>'task','title'=>trim($title),'detail'=>'Event Playbook · '.(string)$playbook['name'],'priority'=>'normal','sort_order'=>$order],$pdo);
        $order+=10;
    }
    $run=(array)$playbook['run_of_show_json'];
    if ($run) coveted_event_production_add_note($admin,$eventRef,'run_of_show',implode("\n",array_map(static fn($v):string=>'• '.trim((string)$v),$run)),$pdo);
    $close=(array)$playbook['closeout_json'];
    if ($close) coveted_event_production_add_note($admin,$eventRef,'closeout','Playbook closeout:\n'.implode("\n",array_map(static fn($v):string=>'• '.trim((string)$v),$close)),$pdo);
}

/** @return array<string,mixed> */
function coveted_event_proposal_convert(array $admin,string $proposalRef,?PDO $pdo=null): array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) throw new InvalidArgumentException('Full System Sample Mode is read-only.');
    $proposal=coveted_event_proposal_by_ref($admin,$proposalRef,$pdo);
    if (!$proposal) throw new InvalidArgumentException('Event Proposal not found.');
    if ((string)$proposal['status']==='converted' && !empty($proposal['converted_event_id'])) {
        $event=coveted_event_by_ref((string)$proposal['converted_event_id']);
        if ($event) return ['event'=>$event,'proposal'=>$proposal,'already_converted'=>true];
    }
    if ((string)$proposal['status']!=='approved') throw new InvalidArgumentException('Approve the proposal before converting it to an Event.');
    $playbook=coveted_event_playbook_by_ref($admin,(string)$proposal['playbook_ref'],$pdo);
    if (!$playbook) throw new InvalidArgumentException('Proposal playbook is unavailable.');

    $location=$pdo->prepare("SELECT l.*,b.status AS business_status FROM locations l JOIN businesses b ON b.id=l.business_id WHERE l.id=? LIMIT 1");
    $location->execute([(int)$proposal['location_id']]);
    $locationRow=$location->fetch();
    if (!$locationRow || (int)$locationRow['business_id']!==(int)$proposal['business_id'] || $locationRow['status']!=='active' || $locationRow['business_status']!=='active') throw new InvalidArgumentException('Proposal partner location is no longer active.');
    $start=coveted_utc_datetime((string)$proposal['proposed_start_at']);
    if ($start->getTimestamp()<=time()) throw new InvalidArgumentException('Proposal date must be in the future before conversion.');
    $end=$start->modify('+'.max(30,(int)$playbook['default_duration_minutes']).' minutes')->format('Y-m-d H:i:s');

    $created=coveted_event_create($admin,(int)$proposal['group_id'],[
        'title'=>(string)$proposal['title'],
        'description'=>(string)($proposal['concept'] ?: 'Approved Coveted Event Proposal.'),
        'event_type'=>(string)$playbook['event_type'],
        'audience'=>(string)$playbook['audience'],
        'timezone'=>(string)($proposal['timezone'] ?: 'America/Phoenix'),
        'starts_at'=>$start->format('Y-m-d H:i:s'),
        'ends_at'=>$end,
        'capacity'=>(int)$proposal['capacity'],
        'plus_one_allowed'=>(int)$playbook['plus_one_allowed'],
        'location_visibility'=>(string)$playbook['location_visibility'],
        'status'=>'draft',
    ]);
    $eventRef=(string)$created['public_id'];
    coveted_event_set_location($admin,$eventRef,(int)$proposal['location_id']);
    if ((int)$proposal['artist_id']>0) coveted_event_set_artist($admin,$eventRef,(int)$proposal['artist_id'],'featured');

    $event=coveted_event_by_ref($eventRef);
    if (!$event) throw new RuntimeException('Converted Event could not be reloaded.');
    $pdo->prepare("UPDATE event_proposals SET status='converted',converted_event_id=?,updated_at=NOW() WHERE id=?")
        ->execute([(int)$event['id'],(int)$proposal['id']]);
    coveted_event_proposal_add_update_record($pdo,(int)$proposal['id'],'conversion','',(string)$proposal['status'],'converted','Converted to canonical Event '.$eventRef.'.',(int)$admin['id']);
    try { coveted_event_proposal_seed_playbook($admin,$proposal,$playbook,$eventRef,$pdo); } catch (Throwable $e) { error_log('Event Playbook production seed failed: '.$e->getMessage()); }
    coveted_audit('event.proposal_converted','event_proposal',(string)$proposal['public_id'],['event_ref'=>$eventRef,'playbook_key'=>(string)$playbook['playbook_key']],(int)$admin['id']);
    return ['event'=>$event,'proposal'=>$proposal,'already_converted'=>false];
}

/** @return array<string,mixed> */
function coveted_event_proposal_agent_context(array $admin, ?PDO $pdo = null): array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) return ['available'=>false,'sample_mode'=>true,'playbooks'=>[],'proposals'=>[],'recommendations'=>[]];
    if (!coveted_event_proposal_schema_available($pdo)) return ['available'=>false,'migration_required'=>true,'playbooks'=>[],'proposals'=>[],'recommendations'=>[]];
    $playbooks=coveted_event_playbooks($admin,false,$pdo);
    $proposals=array_values(array_filter(coveted_event_proposals($admin,'',$pdo),static fn(array $p):bool=>!in_array((string)$p['status'],['converted','declined'],true)));
    $now=time(); $recommendations=[]; $compact=[];
    foreach ($proposals as $proposal) {
        $last=strtotime((string)($proposal['last_activity_at'] ?? $proposal['updated_at'])) ?: $now;
        $age=max(0,(int)floor(($now-$last)/86400));
        $status=(string)$proposal['status'];
        $compact[]=[
            'proposal_ref'=>(string)$proposal['public_id'],'title'=>(string)$proposal['title'],'status'=>$status,
            'playbook'=>(string)$proposal['playbook_name'],'playbook_key'=>(string)$proposal['playbook_key'],
            'group'=>(string)$proposal['group_name'],'partner'=>(string)$proposal['business_name'],'location'=>(string)$proposal['location_name'],
            'proposed_start_at'=>(string)($proposal['proposed_start_at'] ?? ''),'expected_attendance'=>(int)$proposal['expected_attendance'],'capacity'=>(int)$proposal['capacity'],
            'days_since_activity'=>$age,'artist'=>(string)($proposal['artist_name'] ?? ''),
        ];
        if ($status==='approved') {
            $recommendations[]=['priority'=>1,'key'=>'proposal-convert:'.$proposal['public_id'],'category'=>'Events','title'=>'Convert approved proposal · '.$proposal['title'],'detail'=>'This Event Proposal is approved and ready to become a canonical draft Event with its Playbook production plan.','href'=>'/admin/event-proposals.php?proposal='.rawurlencode((string)$proposal['public_id']),'evidence'=>$proposal['playbook_name'].' · '.$proposal['business_name'].' · '.$proposal['group_name']];
        } elseif (in_array($status,['partner_contacted','negotiating'],true) && $age>=3) {
            $recommendations[]=['priority'=>$age>=7?1:2,'key'=>'proposal-followup:'.$proposal['public_id'],'category'=>'Partners','title'=>'Follow up on event proposal · '.$proposal['business_name'],'detail'=>'Partner proposal activity has gone quiet and needs a relationship follow-up.','href'=>'/admin/event-proposals.php?proposal='.rawurlencode((string)$proposal['public_id']),'evidence'=>$age.' days since proposal activity · '.$proposal['playbook_name']];
        } elseif (in_array($status,['idea','reviewing'],true)) {
            $recommendations[]=['priority'=>2,'key'=>'proposal-review:'.$proposal['public_id'],'category'=>'Events','title'=>'Review event proposal · '.$proposal['title'],'detail'=>'Choose the working terms and move this proposal into partner discussion when it is ready.','href'=>'/admin/event-proposals.php?proposal='.rawurlencode((string)$proposal['public_id']),'evidence'=>$proposal['playbook_name'].' · '.$proposal['group_name'].' · '.$proposal['location_name']];
        }
    }
    usort($recommendations,static fn(array $a,array $b):int=>((int)$a['priority']<=> (int)$b['priority']) ?: strcmp((string)$a['key'],(string)$b['key']));
    return [
        'available'=>true,
        'pipeline'=>[
            'active'=>count($proposals),
            'approved'=>count(array_filter($proposals,static fn(array $p):bool=>(string)$p['status']==='approved')),
            'negotiating'=>count(array_filter($proposals,static fn(array $p):bool=>in_array((string)$p['status'],['partner_contacted','negotiating'],true))),
            'stalled'=>count(array_filter($compact,static fn(array $p):bool=>in_array((string)$p['status'],['partner_contacted','negotiating'],true) && (int)$p['days_since_activity']>=3)),
        ],
        'playbooks'=>array_map(static fn(array $p):array=>[
            'key'=>(string)$p['playbook_key'],'name'=>(string)$p['name'],'event_type'=>(string)$p['event_type'],'capacity'=>(int)$p['default_capacity'],'duration_minutes'=>(int)$p['default_duration_minutes'],
            'required_host_roles'=>(array)$p['required_host_roles_json'],'recommended_benefits'=>(array)$p['recommended_benefits_json'],'run_of_show'=>(array)$p['run_of_show_json'],
        ],array_slice($playbooks,0,12)),
        'proposals'=>array_slice($compact,0,20),
        'recommendations'=>array_slice($recommendations,0,12),
        'authority'=>'Playbooks and proposals are planning context. Only Coveted System Admin can approve or convert a proposal into a canonical Event; hosts never create or configure Events.',
    ];
}
