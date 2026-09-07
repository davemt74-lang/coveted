<?php
declare(strict_types=1);

require_once __DIR__ . '/event_guest_mix.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/system_sample_data.php';

/**
 * Invitation Waves + RSVP Forecasting is a planning layer over canonical
 * invitations, RSVPs, attendance and Guest Mix. It never sends invitations or
 * creates a parallel invitation lifecycle.
 */
function coveted_event_invitation_wave_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live Invitation Wave forecasting.');
    }
    return $pdo;
}

/** @return array<string,int|float|null> */
function coveted_event_invitation_wave_history(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT e.id) AS event_count,
            COUNT(ei.id) AS invitation_count,
            COALESCE(SUM(CASE WHEN er.response IS NOT NULL THEN 1 ELSE 0 END),0) AS response_count,
            COALESCE(SUM(CASE WHEN er.response IN ('attending','waitlist') THEN 1 ELSE 0 END),0) AS positive_count,
            COALESCE(SUM(CASE WHEN er.response = 'attending' THEN 1 ELSE 0 END),0) AS attending_count,
            COALESCE(SUM(CASE WHEN er.response = 'declined' THEN 1 ELSE 0 END),0) AS declined_count,
            COALESCE(SUM(CASE WHEN ea.status IN ('checked_in','attended','left_early') THEN 1 ELSE 0 END),0) AS verified_count,
            COALESCE(SUM(CASE WHEN ea.status = 'no_show' THEN 1 ELSE 0 END),0) AS no_show_count,
            COALESCE(SUM(CASE WHEN er.response = 'attending' THEN er.guest_count ELSE 0 END),0) AS guest_seats,
            AVG(CASE WHEN er.responded_at IS NOT NULL THEN GREATEST(0,TIMESTAMPDIFF(HOUR,ei.created_at,er.responded_at)) ELSE NULL END) AS avg_response_hours
         FROM events e
         JOIN event_invitations ei ON ei.event_id=e.id AND ei.status<>'revoked'
         LEFT JOIN event_rsvps er ON er.event_id=e.id AND er.user_id=ei.user_id
         LEFT JOIN event_attendance ea ON ea.event_id=e.id AND ea.user_id=ei.user_id
         WHERE e.group_id=?
           AND e.status='completed'
           AND e.starts_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)"
    );
    $stmt->execute([$groupId]);
    $row = $stmt->fetch() ?: [];

    foreach (['event_count','invitation_count','response_count','positive_count','attending_count','declined_count','verified_count','no_show_count','guest_seats'] as $key) {
        $row[$key] = (int)($row[$key] ?? 0);
    }
    $row['avg_response_hours'] = $row['avg_response_hours'] !== null ? (float)$row['avg_response_hours'] : null;
    return $row;
}

/** @return array<string,mixed> */
function coveted_event_invitation_wave_current_state(PDO $pdo, array $event): array
{
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM event_invitations WHERE event_id=e.id AND status<>'revoked') AS sent_total,
            (SELECT COUNT(*) FROM event_invitations WHERE event_id=e.id AND status='pending') AS pending_invites,
            (SELECT COUNT(*) FROM event_invitations WHERE event_id=e.id AND status='accepted') AS accepted_invites,
            (SELECT COUNT(*) FROM event_invitations WHERE event_id=e.id AND status='declined') AS declined_invites,
            (SELECT MIN(created_at) FROM event_invitations WHERE event_id=e.id AND status='pending') AS oldest_pending_at,
            (SELECT MAX(created_at) FROM event_invitations WHERE event_id=e.id AND status='pending') AS newest_pending_at,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='attending') AS attending_rsvps,
            COALESCE((SELECT SUM(1+guest_count) FROM event_rsvps WHERE event_id=e.id AND response='attending'),0) AS attending_seats,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='waitlist') AS waitlist_rsvps,
            COALESCE((SELECT SUM(1+guest_count) FROM event_rsvps WHERE event_id=e.id AND response='waitlist'),0) AS waitlist_seats,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id AND response='declined') AS declined_rsvps
         FROM events e WHERE e.id=?"
    );
    $stmt->execute([(int)$event['id']]);
    $row = $stmt->fetch() ?: [];
    foreach (['sent_total','pending_invites','accepted_invites','declined_invites','attending_rsvps','attending_seats','waitlist_rsvps','waitlist_seats','declined_rsvps'] as $key) {
        $row[$key] = (int)($row[$key] ?? 0);
    }
    return $row;
}

/** @return array<string,mixed> */
function coveted_event_invitation_wave_rates(array $history, bool $plusOneAllowed): array
{
    $events = (int)($history['event_count'] ?? 0);
    $invites = (int)($history['invitation_count'] ?? 0);
    $attending = (int)($history['attending_count'] ?? 0);

    $confidence = 'low';
    if ($events >= 4 && $invites >= 40) {
        $confidence = 'high';
    } elseif ($events >= 3 && $invites >= 20) {
        $confidence = 'medium';
    } elseif ($events >= 2 && $invites >= 8) {
        $confidence = 'emerging';
    }

    $weight = match ($confidence) {
        'high' => 0.90,
        'medium' => 0.70,
        'emerging' => 0.40,
        default => $invites > 0 ? 0.15 : 0.0,
    };

    // Conservative priors keep tiny samples from producing extreme forecasts.
    $priorResponse = 0.70;
    $priorPositive = 0.55;
    $priorShow = 0.85;
    $priorGuest = 0.10;

    $observedResponse = $invites > 0 ? ((int)$history['response_count'] / $invites) : $priorResponse;
    $observedPositive = $invites > 0 ? ((int)$history['positive_count'] / $invites) : $priorPositive;
    $observedShow = $attending > 0 ? ((int)$history['verified_count'] / $attending) : $priorShow;
    $observedGuest = $attending > 0 ? ((int)$history['guest_seats'] / $attending) : $priorGuest;

    $blend = static fn(float $observed, float $prior, float $w): float => ($observed * $w) + ($prior * (1.0 - $w));
    $responseRate = max(0.05, min(1.0, $blend($observedResponse, $priorResponse, $weight)));
    $positiveRate = max(0.05, min(0.95, $blend($observedPositive, $priorPositive, $weight)));
    $showRate = max(0.50, min(0.99, $blend($observedShow, $priorShow, $weight)));
    $guestRate = $plusOneAllowed ? max(0.0, min(0.75, $blend($observedGuest, $priorGuest, $weight))) : 0.0;

    $responseWindow = (float)($history['avg_response_hours'] ?? 48.0);
    if ($responseWindow <= 0.0) $responseWindow = 48.0;
    $responseWindow = max(12.0, min(96.0, $responseWindow));

    return [
        'confidence' => $confidence,
        'history_weight' => $weight,
        'response_rate' => round($responseRate, 4),
        'positive_rate' => round($positiveRate, 4),
        'show_rate' => round($showRate, 4),
        'guest_rate' => round($guestRate, 4),
        'seats_per_positive_rsvp' => round(1.0 + $guestRate, 4),
        'response_window_hours' => (int)round($responseWindow),
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_event_invitation_wave_candidates(array $guestMix, string $wave, int $limit): array
{
    if ($limit < 1) return [];
    $eligible = array_values(array_filter((array)($guestMix['candidates'] ?? []), static fn(array $c): bool =>
        empty($c['paced_out']) && (int)($c['fit_score'] ?? 0) >= 35
    ));

    $preferred = match ($wave) {
        'wave_1' => ['reliable_repeat','reconnect','small_format'],
        'wave_2' => ['widen_circle','reconnect','balanced','small_format'],
        default => ['reconnect','widen_circle','reliable_repeat','small_format','balanced'],
    };
    $rank = array_flip($preferred);
    usort($eligible, static function (array $a, array $b) use ($rank): int {
        $ar = $rank[(string)($a['segment'] ?? '')] ?? 99;
        $br = $rank[(string)($b['segment'] ?? '')] ?? 99;
        if ($ar !== $br) return $ar <=> $br;
        $score = (int)($b['fit_score'] ?? 0) <=> (int)($a['fit_score'] ?? 0);
        return $score !== 0 ? $score : strcmp((string)($a['display_name'] ?? ''), (string)($b['display_name'] ?? ''));
    });
    return array_slice($eligible, 0, $limit);
}

/** @return array<string,mixed> */
function coveted_event_invitation_wave_snapshot(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_invitation_wave_require_admin($admin, $pdo);
    $guestMix = coveted_event_guest_mix_snapshot($admin, $eventRef, $pdo);
    $event = (array)$guestMix['event'];
    $canonicalEvent = coveted_event_by_ref((string)$event['public_id']);
    if (!$canonicalEvent) throw new InvalidArgumentException('Event not found.');
    $event['plus_one_allowed'] = !empty($canonicalEvent['plus_one_allowed']) ? 1 : 0;
    $event['timezone'] = (string)($canonicalEvent['timezone'] ?? 'UTC');

    $history = coveted_event_invitation_wave_history($pdo, (int)$event['group_id']);
    $state = coveted_event_invitation_wave_current_state($pdo, $event);
    $rates = coveted_event_invitation_wave_rates($history, !empty($event['plus_one_allowed']));

    $capacity = (int)($event['capacity'] ?? 0);
    $targetSeats = $capacity > 0 ? $capacity : 20;
    $desiredArrivals = $capacity > 0 ? max(1, (int)ceil($capacity * 0.90)) : 20;
    $attendingSeats = (int)$state['attending_seats'];
    $pending = (int)$state['pending_invites'];
    $positiveRate = (float)$rates['positive_rate'];
    $showRate = (float)$rates['show_rate'];
    $seatFactor = (float)$rates['seats_per_positive_rsvp'];

    $margin = match ((string)$rates['confidence']) {
        'high' => 0.08,
        'medium' => 0.12,
        'emerging' => 0.18,
        default => 0.25,
    };
    $positiveLow = max(0.05, $positiveRate - $margin);
    $positiveHigh = min(0.98, $positiveRate + $margin);
    $showLow = max(0.50, $showRate - ($margin / 2));
    $showHigh = min(0.995, $showRate + ($margin / 2));

    $expectedPendingPositiveSeats = $pending * $positiveRate * $seatFactor;
    $forecastLow = (int)floor(($attendingSeats + ($pending * $positiveLow * $seatFactor)) * $showLow);
    $forecastExpected = (int)round(($attendingSeats + $expectedPendingPositiveSeats) * $showRate);
    $forecastHigh = (int)ceil(($attendingSeats + ($pending * $positiveHigh * $seatFactor)) * $showHigh);
    if ($capacity > 0) {
        $forecastLow = min($capacity, $forecastLow);
        $forecastExpected = min($capacity, $forecastExpected);
        $forecastHigh = min($capacity, $forecastHigh);
    }
    $forecastLow = max(0, min($forecastLow, $forecastExpected));
    $forecastHigh = max($forecastExpected, $forecastHigh);

    $eventTs = strtotime((string)$event['starts_at']) ?: time();
    $daysToEvent = max(0.0, ($eventTs - time()) / 86400);
    $newestPendingTs = !empty($state['newest_pending_at']) ? (strtotime((string)$state['newest_pending_at']) ?: 0) : 0;
    $oldestPendingTs = !empty($state['oldest_pending_at']) ? (strtotime((string)$state['oldest_pending_at']) ?: 0) : 0;
    $newestPendingAgeHours = $newestPendingTs > 0 ? max(0.0, (time() - $newestPendingTs) / 3600) : null;
    $oldestPendingAgeHours = $oldestPendingTs > 0 ? max(0.0, (time() - $oldestPendingTs) / 3600) : null;

    $effectiveYield = max(0.10, $positiveRate * $showRate * $seatFactor);
    $forecastGap = max(0, $desiredArrivals - $forecastExpected);
    $rawAdditionalInvites = $forecastGap > 0 ? (int)ceil($forecastGap / $effectiveYield) : 0;
    $safeInvitationSlots = max(0, (int)($guestMix['counts']['invitation_slots'] ?? 0));
    $availableCandidates = max(0, (int)($guestMix['counts']['recommended_count'] ?? 0));
    $safeAdditionalInvites = min($rawAdditionalInvites, $safeInvitationSlots, $availableCandidates);

    $fullInviteNeed = max(1, (int)ceil($desiredArrivals / $effectiveYield));
    $wave1Target = max(1, (int)ceil($fullInviteNeed * 0.45));
    $wave2Target = max($wave1Target, (int)ceil($fullInviteNeed * 0.75));
    $sentTotal = (int)$state['sent_total'];
    $planningWave = $sentTotal < $wave1Target ? 'wave_1' : ($sentTotal < $wave2Target ? 'wave_2' : 'wave_3');

    $wave = 'hold';
    $decision = 'hold';
    $recommendedInvites = 0;
    $title = 'Hold the next invitation wave';
    $detail = 'Current responses and pending outreach should resolve before more invitations are sent.';

    $hasWaitlistCapacityGap = $capacity > 0 && (int)$state['waitlist_rsvps'] > 0 && $attendingSeats < $capacity;
    if ((string)$event['status'] === 'draft') {
        $wave = 'wave_1';
        $decision = 'prepare_wave_1';
        $recommendedInvites = min($safeAdditionalInvites, max(1, $wave1Target - $sentTotal));
        $title = 'Prepare Wave 1 after publication';
        $detail = 'Guest Mix and historical response behavior support an initial Core Mix, but canonical invitations cannot be sent until the Event is published.';
    } elseif ($hasWaitlistCapacityGap) {
        $wave = 'waitlist';
        $decision = 'reconcile_waitlist';
        $title = 'Reconcile the waitlist before inviting anyone new';
        $detail = 'The Event has waitlisted members and currently available capacity. Promote eligible waitlisted RSVPs before opening another invitation wave.';
    } elseif ((int)$state['waitlist_rsvps'] > 0) {
        $wave = 'waitlist';
        $decision = 'hold_waitlist';
        $title = 'Hold invitations — the waitlist already covers demand';
        $detail = 'Existing waitlisted demand should be used before any new invitation wave.';
    } elseif ($capacity > 0 && $attendingSeats >= $capacity) {
        $decision = 'hold_full';
        $title = 'Hold invitations — capacity is committed';
        $detail = 'Current attending RSVPs already occupy the Event capacity.';
    } elseif ($forecastExpected >= $desiredArrivals) {
        $decision = 'hold_forecast';
        $title = 'Hold invitations — forecast is on target';
        $detail = 'Confirmed RSVPs plus expected pending responses are already forecast to reach the target attendance range.';
    } elseif ($pending > 0 && $newestPendingAgeHours !== null && $newestPendingAgeHours < (int)$rates['response_window_hours'] && $daysToEvent > 1.0) {
        $decision = 'hold_response_window';
        $title = 'Hold invitations while the current wave responds';
        $detail = 'The newest pending invitations are still inside this group’s confidence-weighted response window.';
    } elseif ($safeAdditionalInvites < 1) {
        $decision = 'hold_no_safe_slots';
        $title = 'Hold invitations — no safe new slots right now';
        $detail = 'Guest Mix pacing, pending invitations, or current capacity leaves no safe new invitation slots.';
    } else {
        if ($daysToEvent <= 2.0) {
            $wave = 'wave_3';
        } else {
            $wave = $planningWave;
        }

        $stageRemaining = match ($wave) {
            'wave_1' => max(1, $wave1Target - $sentTotal),
            'wave_2' => max(1, $wave2Target - $sentTotal),
            default => $safeAdditionalInvites,
        };
        $recommendedInvites = min($safeAdditionalInvites, $stageRemaining);
        $decision = 'open_' . $wave;
        if ($wave === 'wave_1') {
            $title = 'Open Wave 1 — Core Mix';
            $detail = 'Start with reliable-repeat, reconnection and proven small-format fits while keeping enough room for later participation-breadth invitations.';
        } elseif ($wave === 'wave_2') {
            $title = 'Open Wave 2 — Broaden the room';
            $detail = 'The forecast still has room. Add participation-breadth and reconnection candidates without exhausting final fill capacity.';
        } else {
            $title = 'Open Wave 3 — Fill remaining capacity';
            $detail = 'The Event is close enough, or prior waves are mature enough, to use the highest-fit remaining candidates to close the forecast gap.';
        }
    }

    $recommendedCandidates = str_starts_with($decision, 'open_wave_') || $decision === 'prepare_wave_1'
        ? coveted_event_invitation_wave_candidates($guestMix, $wave, $recommendedInvites)
        : [];

    $waveStatus = static function (string $key, int $target, int $sent, string $planningWave): string {
        if ($sent >= $target) return 'complete';
        return $key === $planningWave ? 'active' : 'queued';
    };
    $waves = [
        ['key'=>'wave_1','label'=>'Wave 1 · Core Mix','target_outreach'=>$wave1Target,'status'=>$waveStatus('wave_1',$wave1Target,$sentTotal,$planningWave),'purpose'=>'Reliable anchors, reconnection opportunities and proven format fit.'],
        ['key'=>'wave_2','label'=>'Wave 2 · Broaden','target_outreach'=>$wave2Target,'status'=>$waveStatus('wave_2',$wave2Target,$sentTotal,$planningWave),'purpose'=>'Widen participation with under-engaged and reconnect candidates.'],
        ['key'=>'wave_3','label'=>'Wave 3 · Fill','target_outreach'=>$fullInviteNeed,'status'=>$waveStatus('wave_3',$fullInviteNeed,$sentTotal,$planningWave),'purpose'=>'Close the remaining attendance forecast gap with the strongest safe fit.'],
    ];

    return [
        'event' => $event,
        'state' => $state + [
            'target_seats'=>$targetSeats,
            'desired_arrivals'=>$desiredArrivals,
            'days_to_event'=>round($daysToEvent,1),
            'safe_invitation_slots'=>$safeInvitationSlots,
        ],
        'history' => $history,
        'rates' => $rates,
        'forecast' => [
            'low'=>$forecastLow,
            'expected'=>$forecastExpected,
            'high'=>$forecastHigh,
            'expected_pending_positive_seats'=>round($expectedPendingPositiveSeats,1),
            'gap_to_target'=>$forecastGap,
            'raw_additional_invitations'=>$rawAdditionalInvites,
            'safe_additional_invitations'=>$safeAdditionalInvites,
            'newest_pending_age_hours'=>$newestPendingAgeHours !== null ? round($newestPendingAgeHours,1) : null,
            'oldest_pending_age_hours'=>$oldestPendingAgeHours !== null ? round($oldestPendingAgeHours,1) : null,
        ],
        'decision' => [
            'key'=>$decision,
            'wave'=>$wave,
            'title'=>$title,
            'detail'=>$detail,
            'recommended_invitations'=>$recommendedInvites,
            'recommended_candidates'=>$recommendedCandidates,
        ],
        'waves' => $waves,
        'guest_mix' => [
            'segments'=>(array)($guestMix['segments'] ?? []),
            'candidate_pool'=>(int)($guestMix['counts']['candidate_pool'] ?? 0),
            'paced_members'=>(int)($guestMix['counts']['paced_members'] ?? 0),
            'href'=>'/admin/event-guest-mix.php?event='.rawurlencode((string)$event['public_id']),
        ],
        'privacy' => 'Broad Agent context receives aggregate event/group forecasting only. Recommended member identities remain in the System Admin workspace and Mutual Reconnect choices are not used.',
        'authority' => 'Invitation Waves never send invitations. System Admin explicitly chooses recipients and uses canonical Event invitation controls. Waitlist reconciliation is an explicit System Admin action using the canonical RSVP promotion service.',
    ];
}

/** @return int[] promoted user IDs */
function coveted_event_invitation_wave_reconcile_waitlist(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_invitation_wave_require_admin($admin, $pdo);
    coveted_event_require_system_admin($admin);
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) throw new InvalidArgumentException('Event not found.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT e.*,g.status AS group_status
             FROM events e JOIN social_groups g ON g.id=e.group_id
             WHERE (e.public_id=? OR CAST(e.id AS CHAR)=?)
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$eventRef,$eventRef]);
        $event = $stmt->fetch();
        if (!$event || (string)$event['status'] !== 'published' || !coveted_event_is_future($event)) {
            throw new InvalidArgumentException('Waitlist reconciliation requires a future published Event.');
        }
        $promoted = coveted_event_promote_waitlist_locked($pdo, $event);
        $pdo->commit();
        return $promoted;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return array<string,mixed> */
function coveted_event_invitation_wave_agent_context(array $admin, int $limit = 12, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) return ['available'=>false,'reason'=>'sample_mode','events'=>[],'recommendations'=>[],'attention'=>0];

    $stmt = $pdo->query(
        "SELECT public_id FROM events
         WHERE status='published' AND starts_at>UTC_TIMESTAMP()
           AND starts_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 45 DAY)
         ORDER BY starts_at,id LIMIT 30"
    );
    $events=[];$recommendations=[];$attention=0;
    foreach ($stmt->fetchAll() as $row) {
        if (count($events) >= max(1,min(20,$limit))) break;
        try {
            $snap = coveted_event_invitation_wave_snapshot($admin,(string)$row['public_id'],$pdo);
        } catch (Throwable) {
            continue;
        }
        $event=(array)$snap['event'];$state=(array)$snap['state'];$forecast=(array)$snap['forecast'];$decision=(array)$snap['decision'];$rates=(array)$snap['rates'];
        $actionable = str_starts_with((string)$decision['key'],'open_wave_') || (string)$decision['key']==='reconcile_waitlist';
        if ($actionable) $attention++;
        $events[]=[
            'event_ref'=>(string)$event['public_id'],'title'=>(string)$event['title'],'group'=>(string)$event['group_name'],'starts_at'=>(string)$event['starts_at'],'capacity'=>(int)$event['capacity'],
            'attending_seats'=>(int)$state['attending_seats'],'pending_invitations'=>(int)$state['pending_invites'],'waitlist'=>(int)$state['waitlist_rsvps'],
            'forecast'=>['low'=>(int)$forecast['low'],'expected'=>(int)$forecast['expected'],'high'=>(int)$forecast['high'],'target'=>(int)$state['desired_arrivals']],
            'confidence'=>(string)$rates['confidence'],'response_window_hours'=>(int)$rates['response_window_hours'],
            'decision'=>(string)$decision['key'],'wave'=>(string)$decision['wave'],'recommended_invitations'=>(int)$decision['recommended_invitations'],
            'href'=>'/admin/event-invitation-waves.php?event='.rawurlencode((string)$event['public_id']),
        ];
        if ($actionable && count($recommendations)<12) {
            $recommendations[]=[
                'priority'=>(float)$state['days_to_event']<=3.0?1:2,
                'key'=>'invitation-wave-'.(string)$event['public_id'],'category'=>'Invitations','title'=>(string)$decision['title'],
                'detail'=>(string)$decision['detail'],
                'evidence'=>'Forecast '.(int)$forecast['low'].'–'.(int)$forecast['high'].' arrivals (expected '.(int)$forecast['expected'].') · target '.(int)$state['desired_arrivals'].' · '.(int)$state['pending_invites'].' pending · '.(int)$state['waitlist_rsvps'].' waitlist · '.(string)$rates['confidence'].' confidence.',
                'href'=>'/admin/event-invitation-waves.php?event='.rawurlencode((string)$event['public_id']),
            ];
        }
    }
    return [
        'available'=>true,'events'=>$events,'recommendations'=>$recommendations,'attention'=>$attention,
        'privacy'=>'Aggregate Event invitation/RSVP forecasting only. No recommended member identities, contact details, private messages, pair identities, or Mutual Reconnect choices are included.',
        'authority'=>'Read-only invitation-wave recommendations. System Admin explicitly sends invitations through canonical Event controls; waitlist reconciliation requires an explicit Admin action.',
    ];
}
