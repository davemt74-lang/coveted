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
    $eventZone = new DateTimeZone('America/Phoenix');
    $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
    $localNow = $now->setTimezone($eventZone);
    $baseLocal = $localNow->setTime(19, 0);
    if ($baseLocal <= $localNow) {
        $baseLocal = $baseLocal->modify('+1 day');
    }

    $makeStart = static function (DateTimeImmutable $base, string $offset) use ($utc): string {
        return $base->modify($offset)->setTimezone($utc)->format('Y-m-d H:i:s');
    };

    return [
        [
            'public_id' => 'sample-rooftop-social',
            'title' => 'Rooftop Social',
            'event_type' => 'regular',
            'timezone' => 'America/Phoenix',
            'starts_at' => $makeStart($baseLocal, '+3 days'),
            'is_sample' => true,
        ],
        [
            'public_id' => 'sample-private-dinner',
            'title' => 'Private Dinner',
            'event_type' => 'private_table',
            'timezone' => 'America/Phoenix',
            'starts_at' => $makeStart($baseLocal, '+7 days'),
            'is_sample' => true,
        ],
        [
            'public_id' => 'sample-artist-session',
            'title' => 'Artist Session',
            'event_type' => 'session',
            'timezone' => 'America/Phoenix',
            'starts_at' => $makeStart($baseLocal, '+12 days'),
            'is_sample' => true,
        ],
        [
            'public_id' => 'sample-mystery-gathering',
            'title' => 'Hidden sample title',
            'event_type' => 'mystery',
            'timezone' => 'America/Phoenix',
            'starts_at' => $makeStart($baseLocal, '+18 days'),
            'is_sample' => true,
        ],
    ];
}
