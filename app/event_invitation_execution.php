<?php
declare(strict_types=1);

require_once __DIR__ . '/event_invitation_waves.php';
require_once __DIR__ . '/event_management.php';
require_once __DIR__ . '/admin_agent_tasks.php';
require_once __DIR__ . '/system_sample_data.php';

/**
 * Invitation Wave Execution is the human-approval bridge between the Agent's
 * invitation recommendation and canonical Event invitations. It never selects
 * recipients autonomously and never writes event_invitations directly.
 */
function coveted_event_invitation_execution_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Invitation Wave execution.');
    }
    return $pdo;
}

/** @return array<string,mixed>|null */
function coveted_event_invitation_execution_agent_task(array $admin, string $eventRef, ?PDO $pdo = null): ?array
{
    coveted_event_invitation_execution_require_admin($admin, $pdo);
    $pdo ??= coveted_db();
    if (!coveted_admin_agent_tasks_schema_available($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id,public_id,title,detail,priority,status,source_key,source_href,created_at,updated_at
         FROM admin_agent_tasks
         WHERE owner_user_id=?
           AND source_type='opportunity'
           AND source_key=?
           AND status IN ('suggested','approved','in_progress')
         ORDER BY FIELD(status,'in_progress','approved','suggested'),updated_at DESC,id DESC
         LIMIT 1"
    );
    $stmt->execute([(int)$admin['id'], 'invitation-wave-' . $eventRef]);
    $task = $stmt->fetch();
    return $task ?: null;
}

/** @return array<string,mixed> */
function coveted_event_invitation_execution_preview(array $waveSnapshot, int $selectedCount): array
{
    $selectedCount = max(0, $selectedCount);
    $rates = (array)($waveSnapshot['rates'] ?? []);
    $forecast = (array)($waveSnapshot['forecast'] ?? []);
    $event = (array)($waveSnapshot['event'] ?? []);

    $positive = max(0.05, min(0.95, (float)($rates['positive_rate'] ?? 0.55)));
    $show = max(0.50, min(0.99, (float)($rates['show_rate'] ?? 0.85)));
    $seatFactor = max(1.0, (float)($rates['seats_per_positive_rsvp'] ?? 1.0));
    $margin = match ((string)($rates['confidence'] ?? 'low')) {
        'high' => 0.08,
        'medium' => 0.12,
        'emerging' => 0.18,
        default => 0.25,
    };

    $lowYield = max(0.05, $positive - $margin) * max(0.50, $show - ($margin / 2)) * $seatFactor;
    $expectedYield = $positive * $show * $seatFactor;
    $highYield = min(0.98, $positive + $margin) * min(0.995, $show + ($margin / 2)) * $seatFactor;

    $capacity = max(0, (int)($event['capacity'] ?? 0));
    $cap = static fn(int $value): int => $capacity > 0 ? min($capacity, $value) : $value;

    $baseLow = max(0, (int)($forecast['low'] ?? 0));
    $baseExpected = max(0, (int)($forecast['expected'] ?? 0));
    $baseHigh = max($baseExpected, (int)($forecast['high'] ?? $baseExpected));

    return [
        'selected_count' => $selectedCount,
        'base_low' => $baseLow,
        'base_expected' => $baseExpected,
        'base_high' => $baseHigh,
        'increment_low_per_invite' => round($lowYield, 4),
        'increment_expected_per_invite' => round($expectedYield, 4),
        'increment_high_per_invite' => round($highYield, 4),
        'projected_low' => $cap((int)floor($baseLow + ($selectedCount * $lowYield))),
        'projected_expected' => $cap((int)round($baseExpected + ($selectedCount * $expectedYield))),
        'projected_high' => $cap((int)ceil($baseHigh + ($selectedCount * $highYield))),
        'capacity' => $capacity,
    ];
}

/** @return array<string,mixed> */
function coveted_event_invitation_execution_snapshot(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_invitation_execution_require_admin($admin, $pdo);
    $wave = coveted_event_invitation_wave_snapshot($admin, $eventRef, $pdo);
    $event = (array)($wave['event'] ?? []);
    $decision = (array)($wave['decision'] ?? []);
    $decisionKey = (string)($decision['key'] ?? 'hold');
    $recommended = max(0, (int)($decision['recommended_invitations'] ?? 0));
    $candidates = array_values(array_filter(
        (array)($decision['recommended_candidates'] ?? []),
        static fn(mixed $row): bool => is_array($row) && (int)($row['user_id'] ?? 0) > 0
    ));

    $eventStarts = coveted_utc_datetime((string)($event['starts_at'] ?? ''))->getTimestamp();
    $sendEnabled = str_starts_with($decisionKey, 'open_wave_')
        && $recommended > 0
        && (string)($event['status'] ?? '') === 'published'
        && $eventStarts > time();

    $safeLimit = $sendEnabled ? min($recommended, count($candidates), 50) : 0;
    $candidates = $safeLimit > 0 ? array_slice($candidates, 0, $safeLimit) : [];
    $task = coveted_event_invitation_execution_agent_task($admin, (string)($event['public_id'] ?? $eventRef), $pdo);

    return [
        'event' => $event,
        'wave' => $wave,
        'decision' => $decision,
        'candidates' => $candidates,
        'safe_limit' => $safeLimit,
        'send_enabled' => $sendEnabled,
        'default_preview' => coveted_event_invitation_execution_preview($wave, $safeLimit),
        'agent_task' => $task,
        'agent_management' => [
            'source_key' => 'invitation-wave-' . (string)($event['public_id'] ?? $eventRef),
            'brain_context' => 'Invitation Wave forecasting already flows through canonical Operations into Admin Agent brain context.',
            'proactive_management' => 'The same recommendation is synchronized into the Admin Agent task queue as a proactive opportunity.',
            'execution_boundary' => 'System Admin must review and explicitly select exact recipients. The Agent never chooses or sends recipients autonomously.',
        ],
        'authority' => 'Agent recommends and tracks the invitation wave. System Admin selects exact recipients and explicitly sends the batch through canonical Event invitation services.',
        'privacy' => 'Recipient identities exist only in this System Admin execution workspace. Broad Agent context remains aggregate-only.',
    ];
}

function coveted_event_invitation_execution_mark_agent_in_progress(
    array $admin,
    string $eventRef,
    int $selectedCount,
    int $sentCount,
    ?PDO $pdo = null
): ?array {
    $pdo = coveted_event_invitation_execution_require_admin($admin, $pdo);
    $task = coveted_event_invitation_execution_agent_task($admin, $eventRef, $pdo);
    if (!$task) {
        return null;
    }

    $status = (string)$task['status'];
    $taskRef = (string)$task['public_id'];
    if ($status === 'suggested') {
        coveted_admin_agent_task_set_status($admin, $taskRef, 'approved', $pdo, 'suggested');
        $status = 'approved';
    }
    if ($status === 'approved') {
        coveted_admin_agent_task_set_status($admin, $taskRef, 'in_progress', $pdo, 'approved');
        $status = 'in_progress';
    }

    coveted_audit(
        'admin.agent_invitation_wave_executed',
        'admin_agent_task',
        $taskRef,
        [
            'event_ref' => $eventRef,
            'selected_count' => $selectedCount,
            'sent_count' => $sentCount,
            'status' => $status,
            'recipient_identities_in_agent_payload' => false,
        ],
        (int)$admin['id']
    );

    return coveted_admin_agent_task_by_ref($admin, $taskRef, $pdo);
}

/** @return array<string,mixed> */
function coveted_event_invitation_execution_send_selected(
    array $admin,
    string $eventRef,
    array $selectedUserIds,
    string $inviteType = 'member',
    ?PDO $pdo = null
): array {
    $pdo = coveted_event_invitation_execution_require_admin($admin, $pdo);
    $eventRef = trim($eventRef);
    if ($eventRef === '') {
        throw new InvalidArgumentException('Choose an Event before sending an invitation wave.');
    }

    // Critical revalidation: never trust a recipient list or safe limit rendered
    // earlier in the browser. Rebuild Guest Mix + forecast immediately before send.
    $live = coveted_event_invitation_execution_snapshot($admin, $eventRef, $pdo);
    $canonicalEventRef = (string)($live['event']['public_id'] ?? $eventRef);
    if (empty($live['send_enabled']) || (int)$live['safe_limit'] < 1) {
        throw new InvalidArgumentException('The live forecast no longer recommends sending a new invitation wave. Refresh the queue.');
    }

    $selected = [];
    foreach ($selectedUserIds as $value) {
        $userId = (int)$value;
        if ($userId > 0) $selected[$userId] = $userId;
    }
    $selected = array_values($selected);
    if (!$selected) {
        throw new InvalidArgumentException('Select at least one recommended recipient.');
    }
    if (count($selected) > (int)$live['safe_limit']) {
        throw new InvalidArgumentException('The selected batch exceeds the live safe invitation limit. Refresh the queue and reduce the selection.');
    }

    $currentCandidates = [];
    foreach ((array)$live['candidates'] as $candidate) {
        $uid = (int)($candidate['user_id'] ?? 0);
        if ($uid > 0) $currentCandidates[$uid] = $candidate;
    }
    foreach ($selected as $userId) {
        if (!isset($currentCandidates[$userId])) {
            throw new InvalidArgumentException('A selected recipient is no longer in the current recommended wave. Refresh the queue.');
        }
    }

    $results = [];
    $sent = 0;
    $failed = 0;
    foreach ($selected as $userId) {
        $candidate = $currentCandidates[$userId];
        try {
            $invitationRef = coveted_event_invite_user(
                $admin,
                $canonicalEventRef,
                $userId,
                $inviteType,
                [
                    'require_active_group_member' => true,
                    'reject_event_host' => true,
                    'respect_existing_response' => true,
                    'idempotent_pending' => true,
                ]
            );
            $sent++;
            $results[] = [
                'user_id' => $userId,
                'display_name' => (string)($candidate['display_name'] ?? 'Member'),
                'status' => 'sent',
                'invitation_ref' => $invitationRef,
                'message' => 'Invitation sent through the canonical Event invitation service.',
            ];
        } catch (Throwable $e) {
            $failed++;
            $results[] = [
                'user_id' => $userId,
                'display_name' => (string)($candidate['display_name'] ?? 'Member'),
                'status' => 'failed',
                'invitation_ref' => null,
                'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'Invitation could not be sent.',
            ];
            if (!($e instanceof InvalidArgumentException)) {
                error_log('Invitation Wave recipient send failed: ' . $e->getMessage());
            }
        }
    }

    $agentTask = null;
    $agentTrackingWarning = false;
    if ($sent > 0) {
        try {
            $agentTask = coveted_event_invitation_execution_mark_agent_in_progress(
                $admin,
                $canonicalEventRef,
                count($selected),
                $sent,
                $pdo
            );
        } catch (Throwable $e) {
            // Invitations are already committed one recipient at a time. A concurrent
            // task-queue update must never make a successful batch look rolled back.
            $agentTrackingWarning = true;
            error_log('Invitation Wave Agent task tracking failed: ' . $e->getMessage());
            try {
                $agentTask = coveted_event_invitation_execution_agent_task($admin, $canonicalEventRef, $pdo);
            } catch (Throwable $ignored) {
                $agentTask = null;
            }
        }
    }

    $fresh = coveted_event_invitation_execution_snapshot($admin, $canonicalEventRef, $pdo);
    coveted_audit(
        'event.invitation_wave_batch_executed',
        'event',
        $canonicalEventRef,
        [
            'selected_count' => count($selected),
            'sent_count' => $sent,
            'failed_count' => $failed,
            'wave' => (string)($live['decision']['wave'] ?? ''),
            'decision_before' => (string)($live['decision']['key'] ?? ''),
            'decision_after' => (string)($fresh['decision']['key'] ?? ''),
            'agent_task_ref' => (string)($agentTask['public_id'] ?? ''),
            'agent_tracking_warning' => $agentTrackingWarning,
        ],
        (int)$admin['id']
    );

    return [
        'selected_count' => count($selected),
        'sent_count' => $sent,
        'failed_count' => $failed,
        'results' => $results,
        'before' => $live,
        'after' => $fresh,
        'agent_task' => $agentTask,
        'agent_tracking_warning' => $agentTrackingWarning,
    ];
}
