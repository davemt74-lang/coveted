<?php
declare(strict_types=1);

require_once __DIR__ . '/benefit_sponsorships.php';

/**
 * Recover a Benefit Program created from this exact proposal by using the
 * canonical Builder's existing created_surface metadata. This makes proposal
 * conversion replay-safe without inventing a second program store.
 *
 * @return array<string,mixed>|null
 */
function coveted_benefit_sponsorship_recover_program(array $proposal, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $marker = 'merchant_sponsorship:' . (string)$proposal['public_id'];
    $stmt = $pdo->prepare(
        "SELECT c.public_id, c.status
         FROM campaigns c
         WHERE c.owner_type = 'business'
           AND c.business_id = ?
           AND c.metadata_json LIKE '%\"benefit_program_builder\":true%'
           AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.created_surface')) = ?
         ORDER BY c.id ASC
         LIMIT 1"
    );
    $stmt->execute([(int)$proposal['business_id'], $marker]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array{proposal_ref:string,program_ref:string,status:string,already_converted:bool} */
function coveted_benefit_sponsorship_convert_proposal_to_draft(array $admin, string $proposalRef): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required to approve sponsorship proposals.');
    }

    $pdo = coveted_db();
    coveted_benefit_sponsorship_ensure_schema($pdo);
    $lockKey = coveted_benefit_sponsorship_acquire_lock($proposalRef, $pdo);

    try {
        $proposal = coveted_benefit_sponsorship_by_ref($proposalRef, $pdo);
        if (!$proposal) {
            throw new InvalidArgumentException('Sponsorship proposal not found.');
        }

        if ((string)$proposal['status'] === 'converted') {
            $programRef = trim((string)($proposal['benefit_program_ref'] ?? ''));
            if ($programRef === '') {
                throw new RuntimeException('Converted sponsorship proposal is missing its Benefit Program reference.');
            }
            $program = coveted_benefit_program_by_ref($programRef);
            if (!$program) {
                throw new RuntimeException('Converted sponsorship proposal references an unavailable Benefit Program.');
            }
            return [
                'proposal_ref' => (string)$proposal['public_id'],
                'program_ref' => (string)$program['public_id'],
                'status' => (string)$program['status'],
                'already_converted' => true,
            ];
        }
        if ((string)$proposal['status'] !== 'submitted') {
            throw new InvalidArgumentException('Only submitted sponsorship proposals can be converted to a Benefit Program draft.');
        }

        // A prior request may have successfully created the canonical draft but
        // lost the final proposal-state update. Recover that exact draft before
        // creating anything new.
        $recovered = coveted_benefit_sponsorship_recover_program($proposal, $pdo);
        if ($recovered) {
            $stmt = $pdo->prepare(
                "UPDATE benefit_sponsorship_proposals
                 SET status='converted', benefit_program_ref=?, review_note='Accepted into Benefit Program draft.',
                     reviewed_by_user_id=?, reviewed_at=COALESCE(reviewed_at,UTC_TIMESTAMP()), updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND status='submitted'"
            );
            $stmt->execute([(string)$recovered['public_id'], (int)$admin['id'], (int)$proposal['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Sponsorship proposal changed while its existing Benefit Program was being recovered.');
            }
            coveted_audit(
                'benefit_sponsorship.conversion_recovered',
                'benefit_sponsorship_proposal',
                (string)$proposal['public_id'],
                ['program_ref' => (string)$recovered['public_id']],
                (int)$admin['id']
            );
            return [
                'proposal_ref' => (string)$proposal['public_id'],
                'program_ref' => (string)$recovered['public_id'],
                'status' => (string)$recovered['status'],
                'already_converted' => true,
            ];
        }

        $created = coveted_benefit_program_create_draft($admin, [
            'owner_type' => 'business',
            'owner_ref' => (string)$proposal['business_ref'],
            'program_title' => (string)$proposal['program_title'],
            'reward_title' => (string)$proposal['reward_title'],
            'description' => (string)($proposal['description'] ?? ''),
            'reward_type' => (string)$proposal['reward_type'],
            'claim_mode' => (string)$proposal['claim_mode'],
            'value_amount' => $proposal['value_amount'] !== null ? (string)$proposal['value_amount'] : '',
            'value_text' => (string)($proposal['value_text'] ?? ''),
            'trigger_key' => (string)$proposal['trigger_key'],
            'quantity_limit' => (string)$proposal['quantity_limit'],
            'per_user_limit' => (string)$proposal['per_user_limit'],
            'starts_at' => (string)($proposal['starts_at'] ?? ''),
            'ends_at' => (string)($proposal['ends_at'] ?? ''),
            'event_ref' => (string)($proposal['event_ref'] ?? ''),
            'location_ref' => (string)$proposal['location_ref'],
            'created_surface' => 'merchant_sponsorship:' . (string)$proposal['public_id'],
        ]);

        $programRef = (string)$created['public_id'];
        $stmt = $pdo->prepare(
            "UPDATE benefit_sponsorship_proposals
             SET status='converted', benefit_program_ref=?, review_note='Accepted into Benefit Program draft.',
                 reviewed_by_user_id=?, reviewed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP()
             WHERE id=? AND status='submitted'"
        );
        $stmt->execute([$programRef, (int)$admin['id'], (int)$proposal['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Benefit Program draft was created, but proposal state changed before conversion could finish. Retry safely; Coveted will recover the existing draft.');
        }

        coveted_audit(
            'benefit_sponsorship.converted',
            'benefit_sponsorship_proposal',
            (string)$proposal['public_id'],
            [
                'business_ref' => (string)$proposal['business_ref'],
                'program_ref' => $programRef,
                'program_status' => 'draft',
            ],
            (int)$admin['id']
        );
        coveted_benefit_sponsorship_notify_submitter($proposal, 'converted', $programRef, '');

        return [
            'proposal_ref' => (string)$proposal['public_id'],
            'program_ref' => $programRef,
            'status' => 'draft',
            'already_converted' => false,
        ];
    } finally {
        coveted_benefit_sponsorship_release_lock($lockKey, $pdo);
    }
}
