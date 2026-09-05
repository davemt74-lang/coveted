<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';

/**
 * Synthetic landing-page events used only when System Admin enables sample mode.
 * These records are never inserted into the database and can never receive
 * invitations, RSVPs, claims, attendance, or campaign state.
 *
 * @return array<int,array{public_id:string,title:string,event_type:string,timezone:string,starts_at:string,is_sample:bool}>
 */
function coveted_sample_landing_events(?DateTimeImmutable $now = null): array
{
    $utc = new DateTimeZone('UTC');
    $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
    $base = $now->setTime(19, 0);
    if ($base <= $now) {
        $base = $base->modify('+1 day');
    }

    return [
        [
            'public_id' => 'sample-rooftop-social',
            'title' => 'Rooftop Social',
            'event_type' => 'regular',
            'timezone' => 'America/Phoenix',
            'starts_at' => $base->modify('+3 days')->format('Y-m-d H:i:s'),
            'is_sample' => true,
        ],
        [
            'public_id' => 'sample-private-dinner',
            'title' => 'Private Dinner',
            'event_type' => 'private_table',
            'timezone' => 'America/Phoenix',
            'starts_at' => $base->modify('+7 days')->format('Y-m-d H:i:s'),
            'is_sample' => true,
        ],
        [
            'public_id' => 'sample-artist-session',
            'title' => 'Artist Session',
            'event_type' => 'session',
            'timezone' => 'America/Phoenix',
            'starts_at' => $base->modify('+12 days')->format('Y-m-d H:i:s'),
            'is_sample' => true,
        ],
        [
            'public_id' => 'sample-mystery-gathering',
            'title' => 'Hidden sample title',
            'event_type' => 'mystery',
            'timezone' => 'America/Phoenix',
            'starts_at' => $base->modify('+18 days')->format('Y-m-d H:i:s'),
            'is_sample' => true,
        ],
    ];
}
