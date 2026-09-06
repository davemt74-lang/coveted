<?php
declare(strict_types=1);

require_once __DIR__ . '/venue_relationships.php';
require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/notifications.php';

const COVETED_PARTNER_PERK_LOCK = 'coveted:partner-perks:v1';

/** @return array<string,string> */
function coveted_partner_perk_types(): array
{
    return [
        'member_discount' => 'Member discount',
        'member_perk' => 'Recurring member perk',
        'preferred_access' => 'Preferred access',
        'surprise_reward' => 'Surprise reward',
        'return_visit' => 'Return-visit offer',
    ];
}

/** @return array<string,string> */
function coveted_partner_perk_distribution_modes(): array
{
    return [
        'once' => 'Once per member',
        'monthly' => 'Monthly while active',
        'manual' => 'Manual issue',
    ];
}

function coveted_partner_perks_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE 'partner_perks'")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function coveted_partner_perk_try_lock(PDO $pdo): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([COVETED_PARTNER_PERK_LOCK]);
    return (int)$stmt->fetchColumn() === 1;
}

function coveted_partner_perk_unlock(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([COVETED_PARTNER_PERK_LOCK]);
    } catch (Throwable $e) {
        error_log('Coveted Partner Perk lock release failed: ' . $e->getMessage());
    }
}

/** @return array<string,mixed>|null */
function coveted_partner_perk_by_ref(string $ref, ?PDO $pdo = null): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 64) return null;
    $pdo ??= coveted_db();
    if (!coveted_partner_perks_schema_available($pdo)) return null;

    $stmt = $pdo->prepare(
        "SELECT pp.*,
                b.public_id AS business_public_id,b.name AS business_name,b.status AS business_status,
                g.public_id AS group_public_id,g.name AS group_name,g.status AS group_status,
                l.public_id AS location_public_id,l.name AS location_name,l.status AS location_status,
                c.public_id AS campaign_public_id,c.title AS campaign_title,c.status AS campaign_status,
                c.trigger_key,c.location_id AS campaign_location_id,c.quantity_limit,c.per_user_limit,
                rt.public_id AS reward_public_id,rt.title AS reward_title,rt.reward_type,
                rt.claim_mode,rt.status AS reward_status
         FROM partner_perks pp
         JOIN businesses b ON b.id=pp.business_id
         JOIN social_groups g ON g.id=pp.group_id
         JOIN locations l ON l.id=pp.location_id
         JOIN campaigns c ON c.id=pp.campaign_id
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         WHERE pp.public_id=? OR CAST(pp.id AS CHAR)=?
         LIMIT 1"
    );
    $stmt->execute([$ref, $ref]);
    $perk = $stmt->fetch();
    return $perk ?: null;
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_perk_campaign_candidates(array $actor, int $businessId, int $locationId): array
{
    if (!coveted_business_actor_can_manage($actor, $businessId)) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }
    $stmt = coveted_db()->prepare(
        "SELECT c.public_id,c.title,c.status,c.trigger_key,c.quantity_limit,c.per_user_limit,
                rt.title AS reward_title,rt.reward_type,rt.claim_mode,rt.status AS reward_status
         FROM campaigns c
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         WHERE c.owner_type='business' AND c.business_id=? AND c.location_id=?
           AND c.trigger_key='manual' AND c.status<>'archived'
           AND rt.owner_type='business' AND rt.business_id=? AND rt.status<>'archived'
         ORDER BY FIELD(c.status,'active','paused','draft'),c.updated_at DESC,c.id DESC"
    );
    $stmt->execute([$businessId, $locationId, $businessId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_partner_perk_relationship_state(int $businessId, int $groupId, int $locationId, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        "SELECT vr.relationship_status,COALESCE(vr.benefits_enabled,0) AS benefits_enabled,
                b.status AS business_status,g.status AS group_status,l.status AS location_status
         FROM locations l
         JOIN businesses b ON b.id=l.business_id
         JOIN social_groups g ON g.id=?
         LEFT JOIN venue_relationships vr ON vr.group_id=g.id AND vr.location_id=l.id
         WHERE l.id=? AND l.business_id=? LIMIT 1"
    );
    $stmt->execute([$groupId, $locationId, $businessId]);
    $row = $stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Partner relationship is not available.');
    return $row;
}

/** @return array<string,mixed> */
function coveted_partner_perk_validate_campaign(
    int $businessId,
    int $locationId,
    string $campaignRef,
    bool $requireActive = false
): array {
    $campaign = coveted_campaign_by_ref($campaignRef);
    if (!$campaign) throw new InvalidArgumentException('Choose a valid Business campaign.');
    if ((string)$campaign['owner_type'] !== 'business' || (int)$campaign['business_id'] !== $businessId) {
        throw new InvalidArgumentException('Partner Perks must use a campaign owned by this business.');
    }
    if ((int)($campaign['location_id'] ?? 0) !== $locationId) {
        throw new InvalidArgumentException('Partner Perks require a campaign scoped to this exact business location.');
    }
    if ((string)$campaign['trigger_key'] !== 'manual') {
        throw new InvalidArgumentException('Partner Perks require a manual-trigger Business campaign so perk cadence remains authoritative.');
    }

    $template = coveted_reward_template_by_ref((string)$campaign['reward_template_id']);
    if (!$template || (string)$template['owner_type'] !== 'business' || (int)$template['business_id'] !== $businessId) {
        throw new InvalidArgumentException('Partner Perk campaign reward ownership is inconsistent.');
    }
    if ($requireActive && ((string)$campaign['status'] !== 'active' || (string)$template['status'] !== 'active')) {
        throw new InvalidArgumentException('Activate both the Business reward and campaign before activating this Partner Perk.');
    }

    return $campaign + [
        'reward_status' => (string)$template['status'],
        'reward_title' => (string)$template['title'],
        'reward_type' => (string)$template['reward_type'],
        'claim_mode' => (string)$template['claim_mode'],
    ];
}

function coveted_partner_perk_assert_activatable(array $perk): void
{
    $relationship = coveted_partner_perk_relationship_state(
        (int)$perk['business_id'],
        (int)$perk['group_id'],
        (int)$perk['location_id']
    );
    if (
        (string)$relationship['business_status'] !== 'active'
        || (string)$relationship['group_status'] !== 'active'
        || (string)$relationship['location_status'] !== 'active'
    ) {
        throw new InvalidArgumentException('Business, group and location must all be active before this Partner Perk can run.');
    }
    if ((int)$relationship['benefits_enabled'] !== 1) {
        throw new InvalidArgumentException('Enable Partner benefits on this venue relationship before activating a Partner Perk.');
    }
    coveted_partner_perk_validate_campaign(
        (int)$perk['business_id'],
        (int)$perk['location_id'],
        (string)$perk['campaign_id'],
        true
    );
}

/** @return array{starts_at:?string,ends_at:?string} */
function coveted_partner_perk_window(array $data): array
{
    $startsAt = trim((string)($data['starts_at'] ?? '')) ?: null;
    $endsAt = trim((string)($data['ends_at'] ?? '')) ?: null;
    if ($startsAt !== null) $startsAt = coveted_utc_datetime($startsAt)->format('Y-m-d H:i:s');
    if ($endsAt !== null) $endsAt = coveted_utc_datetime($endsAt)->format('Y-m-d H:i:s');
    if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
        throw new InvalidArgumentException('Partner Perk end time must be after its start time.');
    }
    return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
}

/** @return array{id:int,public_id:string} */
function coveted_partner_perk_create(array $actor, int $businessId, string $groupRef, string $locationRef, array $data): array
{
    if (!coveted_partner_perks_schema_available()) {
        throw new RuntimeException('Partner Perks database migration is not installed.');
    }
    if (!coveted_business_actor_can_manage($actor, $businessId)) {
        throw new InvalidArgumentException('Business Admin access is required.');
    }

    $relationship = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);
    $title = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $perkType = strtolower(trim((string)($data['perk_type'] ?? 'member_perk')));
    $mode = strtolower(trim((string)($data['distribution_mode'] ?? 'once')));
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    $window = coveted_partner_perk_window($data);

    if ($title === '' || mb_strlen($title) > 190) throw new InvalidArgumentException('Enter a Partner Perk title.');
    if (mb_strlen($description) > 4000) throw new InvalidArgumentException('Partner Perk description is too long.');
    if (!isset(coveted_partner_perk_types()[$perkType])) throw new InvalidArgumentException('Choose a valid Partner Perk type.');
    if (!isset(coveted_partner_perk_distribution_modes()[$mode])) throw new InvalidArgumentException('Choose a valid Partner Perk distribution mode.');
    if (!in_array($status, ['draft','active'], true)) throw new InvalidArgumentException('New Partner Perks must be draft or active.');

    $campaign = coveted_partner_perk_validate_campaign(
        $businessId,
        (int)$relationship['location_id'],
        trim((string)($data['campaign_ref'] ?? '')),
        $status === 'active'
    );
    $shape = [
        'business_id' => $businessId,
        'group_id' => (int)$relationship['group_id'],
        'location_id' => (int)$relationship['location_id'],
        'campaign_id' => (int)$campaign['id'],
    ];
    if ($status === 'active') coveted_partner_perk_assert_activatable($shape);

    $pdo = coveted_db();
    try {
        $publicId = coveted_uuid('prk');
        $pdo->prepare(
            "INSERT INTO partner_perks
                (public_id,business_id,group_id,location_id,campaign_id,title,description,
                 perk_type,distribution_mode,status,starts_at,ends_at,created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $publicId,$businessId,(int)$relationship['group_id'],(int)$relationship['location_id'],(int)$campaign['id'],
            $title,$description !== '' ? $description : null,$perkType,$mode,$status,
            $window['starts_at'],$window['ends_at'],(int)$actor['id'],
        ]);
        $id = (int)$pdo->lastInsertId();
        coveted_audit('partner_perk.created','partner_perk',$publicId,[
            'business_id' => $businessId,
            'group_id' => (int)$relationship['group_id'],
            'location_id' => (int)$relationship['location_id'],
            'campaign_id' => (string)$campaign['public_id'],
            'perk_type' => $perkType,
            'distribution_mode' => $mode,
            'status' => $status,
        ],(int)$actor['id']);
        return ['id' => $id, 'public_id' => $publicId];
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('That campaign is already assigned as a Partner Perk for this relationship.', 0, $e);
        }
        throw $e;
    }
}

function coveted_partner_perk_set_status(array $actor, string $perkRef, string $status): void
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['draft','active','paused','archived'], true)) {
        throw new InvalidArgumentException('Invalid Partner Perk status.');
    }
    $perk = coveted_partner_perk_by_ref($perkRef);
    if (!$perk) throw new InvalidArgumentException('Partner Perk not found.');
    if (!coveted_business_actor_can_manage($actor, (int)$perk['business_id'])) {
        throw new InvalidArgumentException('You cannot manage this Partner Perk.');
    }
    if ($status === 'active') coveted_partner_perk_assert_activatable($perk);
    coveted_db()->prepare('UPDATE partner_perks SET status=?,updated_at=NOW() WHERE id=?')
        ->execute([$status,(int)$perk['id']]);
    coveted_audit('partner_perk.status_changed','partner_perk',(string)$perk['public_id'],['status' => $status],(int)$actor['id']);
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_perks_for_relationship(array $actor, int $businessId, string $groupRef, string $locationRef): array
{
    if (!coveted_partner_perks_schema_available()) return [];
    $relationship = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);
    $stmt = coveted_db()->prepare(
        "SELECT pp.*,
                c.public_id AS campaign_public_id,c.title AS campaign_title,c.status AS campaign_status,
                c.quantity_limit,c.per_user_limit,
                rt.public_id AS reward_public_id,rt.title AS reward_title,rt.reward_type,
                rt.claim_mode,rt.status AS reward_status,
                COUNT(DISTINCT CASE WHEN ri.status<>'cancelled' THEN ri.id END) AS issued_count,
                COUNT(DISTINCT CASE WHEN rc.status='claimed' THEN rc.id END) AS claimed_count
         FROM partner_perks pp
         JOIN campaigns c ON c.id=pp.campaign_id
         JOIN reward_templates rt ON rt.id=c.reward_template_id
         LEFT JOIN reward_issuances ri ON ri.campaign_id=c.id AND ri.idempotency_key LIKE CONCAT('partner-perk:',pp.id,':%')
         LEFT JOIN reward_claims rc ON rc.reward_issuance_id=ri.id
         WHERE pp.business_id=? AND pp.group_id=? AND pp.location_id=? AND pp.status<>'archived'
         GROUP BY pp.id,c.id,rt.id
         ORDER BY FIELD(pp.status,'active','paused','draft'),pp.updated_at DESC,pp.id DESC"
    );
    $stmt->execute([$businessId,(int)$relationship['group_id'],(int)$relationship['location_id']]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_perk_targets(int $limit = 250, ?PDO $pdo = null): array
{
    $limit = max(1,min($limit,1000));
    $pdo ??= coveted_db();
    if (!coveted_partner_perks_schema_available($pdo)) return [];
    $period = gmdate('Y-m');

    $stmt = $pdo->prepare(
        "SELECT pp.id AS perk_id,pp.public_id AS perk_public_id,pp.title AS perk_title,
                pp.perk_type,pp.distribution_mode,pp.business_id,pp.group_id,pp.location_id,
                c.id AS campaign_id,c.public_id AS campaign_public_id,
                g.public_id AS group_public_id,g.name AS group_name,
                l.public_id AS location_public_id,l.name AS location_name,
                b.public_id AS business_public_id,b.name AS business_name,
                rt.title AS reward_title,gm.user_id
         FROM partner_perks pp
         JOIN businesses b ON b.id=pp.business_id AND b.status='active'
         JOIN social_groups g ON g.id=pp.group_id AND g.status='active'
         JOIN locations l ON l.id=pp.location_id AND l.business_id=pp.business_id AND l.status='active'
         JOIN venue_relationships vr ON vr.group_id=pp.group_id AND vr.location_id=pp.location_id AND COALESCE(vr.benefits_enabled,0)=1
         JOIN campaigns c ON c.id=pp.campaign_id AND c.owner_type='business' AND c.business_id=pp.business_id
           AND c.location_id=pp.location_id AND c.trigger_key='manual' AND c.status='active'
         JOIN reward_templates rt ON rt.id=c.reward_template_id AND rt.owner_type='business'
           AND rt.business_id=pp.business_id AND rt.status='active'
         JOIN group_memberships gm ON gm.group_id=pp.group_id AND gm.membership_status='active'
         JOIN users u ON u.id=gm.user_id AND u.status='active'
         WHERE pp.status='active' AND pp.distribution_mode IN ('once','monthly')
           AND (pp.starts_at IS NULL OR pp.starts_at<=UTC_TIMESTAMP())
           AND (pp.ends_at IS NULL OR pp.ends_at>UTC_TIMESTAMP())
           AND (c.starts_at IS NULL OR c.starts_at<=UTC_TIMESTAMP())
           AND (c.ends_at IS NULL OR c.ends_at>UTC_TIMESTAMP())
           AND (rt.starts_at IS NULL OR rt.starts_at<=UTC_TIMESTAMP())
           AND (rt.expires_at IS NULL OR rt.expires_at>UTC_TIMESTAMP())
           AND (c.quantity_limit IS NULL OR (
               SELECT COUNT(*) FROM reward_issuances qri
               WHERE qri.campaign_id=c.id AND qri.status<>'cancelled'
           ) < c.quantity_limit)
           AND (c.per_user_limit IS NULL OR (
               SELECT COUNT(*) FROM reward_issuances uri
               WHERE uri.campaign_id=c.id AND uri.user_id=gm.user_id AND uri.status<>'cancelled'
           ) < c.per_user_limit)
           AND NOT EXISTS (
               SELECT 1 FROM reward_issuances ri
               WHERE ri.idempotency_key=CASE
                   WHEN pp.distribution_mode='once' THEN CONCAT('partner-perk:',pp.id,':once:user:',gm.user_id)
                   ELSE CONCAT('partner-perk:',pp.id,':month:',?,':user:',gm.user_id)
               END
           )
         ORDER BY pp.created_at ASC,pp.id ASC,gm.created_at ASC,gm.id ASC
         LIMIT {$limit}"
    );
    $stmt->execute([$period]);
    return $stmt->fetchAll();
}

function coveted_partner_perk_idempotency_key(array $target): string
{
    $base = 'partner-perk:' . (int)$target['perk_id'] . ':';
    return (string)$target['distribution_mode'] === 'monthly'
        ? $base . 'month:' . gmdate('Y-m') . ':user:' . (int)$target['user_id']
        : $base . 'once:user:' . (int)$target['user_id'];
}

function coveted_partner_perk_expected_skip(string $message): bool
{
    return in_array($message,[
        'Campaign distribution limit has been reached.',
        'Member campaign limit has been reached.',
        'Campaign is not active.',
        'Campaign is not active yet.',
        'Campaign has ended.',
        'Campaign owner is not active.',
        'Reward has expired.',
        'Member account is not active.',
    ],true);
}

function coveted_partner_perk_notify(array $target, array $issuance): void
{
    $issuanceRef = trim((string)($issuance['public_id'] ?? ''));
    if ($issuanceRef === '') return;
    try {
        coveted_notification_create(
            (int)$target['user_id'],
            'reward.partner_perk_unlocked',
            'New partner perk · ' . (string)$target['location_name'],
            (string)$target['reward_title'],
            '/benefits.php?box=ready&source=business',
            [
                'reward_issuance_id' => $issuanceRef,
                'partner_perk_id' => (string)$target['perk_public_id'],
                'business_id' => (string)$target['business_public_id'],
                'group_id' => (string)$target['group_public_id'],
                'location_id' => (string)$target['location_public_id'],
            ],
            'normal',
            'partner-perk:' . $issuanceRef
        );
    } catch (Throwable $e) {
        error_log('Coveted Partner Perk notification failed: ' . $e->getMessage());
    }
}

/** @return array<string,int|bool> */
function coveted_partner_perk_reconcile(int $limit = 250): array
{
    $limit = max(1,min($limit,1000));
    $summary = [
        'issued' => 0,'already_issued' => 0,'limit_skips' => 0,'failures' => 0,
        'more_work_possible' => false,'skipped_locked' => false,'unavailable' => false,
    ];
    $pdo = coveted_db();
    if (!coveted_partner_perks_schema_available($pdo)) {
        $summary['unavailable'] = true;
        return $summary;
    }
    if (!coveted_partner_perk_try_lock($pdo)) {
        $summary['skipped_locked'] = true;
        return $summary;
    }

    try {
        $targets = coveted_partner_perk_targets($limit,$pdo);
        foreach ($targets as $target) {
            $key = coveted_partner_perk_idempotency_key($target);
            $existing = coveted_reward_existing_idempotent($pdo,$key);
            if ($existing) {
                $summary['already_issued']++;
                coveted_partner_perk_notify($target,$existing);
                continue;
            }
            try {
                $issuance = coveted_reward_issue(
                    (int)$target['campaign_id'],
                    (int)$target['user_id'],
                    null,
                    [
                        'automation' => 'partner_perk',
                        'partner_perk_id' => (string)$target['perk_public_id'],
                        'perk_type' => (string)$target['perk_type'],
                        'distribution_mode' => (string)$target['distribution_mode'],
                        'business_id' => (string)$target['business_public_id'],
                        'group_id' => (string)$target['group_public_id'],
                        'location_id' => (string)$target['location_public_id'],
                    ],
                    $key
                );
                $summary['issued']++;
                coveted_partner_perk_notify($target,$issuance);
            } catch (InvalidArgumentException $e) {
                if (coveted_partner_perk_expected_skip($e->getMessage())) $summary['limit_skips']++;
                else {
                    $summary['failures']++;
                    error_log('Coveted Partner Perk target failed: ' . $e->getMessage());
                }
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted Partner Perk issuance failed: ' . $e->getMessage());
            }
        }
        if (count($targets) >= $limit) $summary['more_work_possible'] = (bool)coveted_partner_perk_targets(1,$pdo);
        if ($summary['issued'] > 0 || $summary['failures'] > 0) {
            coveted_audit('partner_perk.reconciled','platform',null,[
                'issued' => $summary['issued'],
                'already_issued' => $summary['already_issued'],
                'limit_skips' => $summary['limit_skips'],
                'failures' => $summary['failures'],
            ],0);
        }
        return $summary;
    } finally {
        coveted_partner_perk_unlock($pdo);
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_partner_perk_manual_targets(array $perk, string $date, int $limit): array
{
    $pdo = coveted_db();
    $quantityLimit = $perk['quantity_limit'] !== null ? (int)$perk['quantity_limit'] : null;
    $perUserLimit = $perk['per_user_limit'] !== null ? (int)$perk['per_user_limit'] : null;
    $stmt = $pdo->prepare(
        "SELECT ? AS perk_id,? AS perk_public_id,? AS perk_title,? AS perk_type,'manual' AS distribution_mode,
                ? AS campaign_id,? AS campaign_public_id,? AS business_public_id,? AS business_name,
                ? AS group_public_id,? AS group_name,? AS location_public_id,? AS location_name,
                ? AS reward_title,gm.user_id
         FROM group_memberships gm
         JOIN users u ON u.id=gm.user_id AND u.status='active'
         WHERE gm.group_id=? AND gm.membership_status='active'
           AND (? IS NULL OR (SELECT COUNT(*) FROM reward_issuances qri WHERE qri.campaign_id=? AND qri.status<>'cancelled') < ?)
           AND (? IS NULL OR (SELECT COUNT(*) FROM reward_issuances uri WHERE uri.campaign_id=? AND uri.user_id=gm.user_id AND uri.status<>'cancelled') < ?)
           AND NOT EXISTS (
               SELECT 1 FROM reward_issuances ri
               WHERE ri.idempotency_key=CONCAT('partner-perk:',?,':manual:',?,':user:',gm.user_id)
           )
         ORDER BY gm.created_at ASC,gm.id ASC
         LIMIT {$limit}"
    );
    $stmt->execute([
        (int)$perk['id'],(string)$perk['public_id'],(string)$perk['title'],(string)$perk['perk_type'],
        (int)$perk['campaign_id'],(string)$perk['campaign_public_id'],
        (string)$perk['business_public_id'],(string)$perk['business_name'],
        (string)$perk['group_public_id'],(string)$perk['group_name'],
        (string)$perk['location_public_id'],(string)$perk['location_name'],(string)$perk['reward_title'],
        (int)$perk['group_id'],
        $quantityLimit,(int)$perk['campaign_id'],$quantityLimit,
        $perUserLimit,(int)$perk['campaign_id'],$perUserLimit,
        (int)$perk['id'],$date,
    ]);
    return $stmt->fetchAll();
}

/** @return array<string,int|bool> */
function coveted_partner_perk_issue_today(array $actor, string $perkRef, int $limit = 500): array
{
    $limit = max(1,min($limit,1000));
    $perk = coveted_partner_perk_by_ref($perkRef);
    if (!$perk) throw new InvalidArgumentException('Partner Perk not found.');
    if (!coveted_business_actor_can_manage($actor,(int)$perk['business_id'])) {
        throw new InvalidArgumentException('You cannot issue this Partner Perk.');
    }
    if ((string)$perk['status'] !== 'active' || (string)$perk['distribution_mode'] !== 'manual') {
        throw new InvalidArgumentException('Only an active manual Partner Perk can be issued on demand.');
    }
    coveted_partner_perk_assert_activatable($perk);

    $date = gmdate('Y-m-d');
    $targets = coveted_partner_perk_manual_targets($perk,$date,$limit);
    $pdo = coveted_db();
    $summary = ['issued' => 0,'already_issued' => 0,'limit_skips' => 0,'failures' => 0,'more_work_possible' => false];
    foreach ($targets as $target) {
        $key = 'partner-perk:' . (int)$perk['id'] . ':manual:' . $date . ':user:' . (int)$target['user_id'];
        $existing = coveted_reward_existing_idempotent($pdo,$key);
        if ($existing) {
            $summary['already_issued']++;
            continue;
        }
        try {
            $issuance = coveted_reward_issue(
                (int)$perk['campaign_id'],
                (int)$target['user_id'],
                null,
                [
                    'automation' => 'partner_perk_manual',
                    'partner_perk_id' => (string)$perk['public_id'],
                    'perk_type' => (string)$perk['perk_type'],
                    'distribution_mode' => 'manual',
                    'business_id' => (string)$perk['business_public_id'],
                    'group_id' => (string)$perk['group_public_id'],
                    'location_id' => (string)$perk['location_public_id'],
                ],
                $key
            );
            $summary['issued']++;
            coveted_partner_perk_notify($target,$issuance);
        } catch (InvalidArgumentException $e) {
            if (coveted_partner_perk_expected_skip($e->getMessage())) $summary['limit_skips']++;
            else {
                $summary['failures']++;
                error_log('Coveted manual Partner Perk target failed: ' . $e->getMessage());
            }
        } catch (Throwable $e) {
            $summary['failures']++;
            error_log('Coveted manual Partner Perk issuance failed: ' . $e->getMessage());
        }
    }
    if (count($targets) >= $limit) $summary['more_work_possible'] = (bool)coveted_partner_perk_manual_targets($perk,$date,1);
    coveted_audit('partner_perk.manual_issued','partner_perk',(string)$perk['public_id'],$summary + ['issue_date' => $date],(int)$actor['id']);
    return $summary;
}

/** @return array<string,mixed> */
function coveted_partner_perk_agent_context(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_partner_perks_schema_available($pdo)) {
        return ['unavailable' => true,'active' => 0,'monthly' => 0,'manual' => 0,'issued_30d' => 0,'claimed_30d' => 0];
    }
    $row = $pdo->query(
        "SELECT
            SUM(pp.status='active') AS active,
            SUM(pp.status='active' AND pp.distribution_mode='monthly') AS monthly,
            SUM(pp.status='active' AND pp.distribution_mode='manual') AS manual,
            (SELECT COUNT(*) FROM reward_issuances ri
             WHERE ri.idempotency_key LIKE 'partner-perk:%' AND ri.status<>'cancelled'
               AND ri.issued_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS issued_30d,
            (SELECT COUNT(*) FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id=rc.reward_issuance_id
             WHERE ri.idempotency_key LIKE 'partner-perk:%' AND rc.status='claimed'
               AND rc.claimed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) AS claimed_30d
         FROM partner_perks pp"
    )->fetch() ?: [];
    foreach (['active','monthly','manual','issued_30d','claimed_30d'] as $key) $row[$key] = (int)($row[$key] ?? 0);
    $row['unavailable'] = false;
    return $row;
}
