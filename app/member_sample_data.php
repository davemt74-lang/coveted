<?php
declare(strict_types=1);

require_once __DIR__ . '/site_settings.php';

/**
 * True only for a System Admin previewing the signed-in member experience.
 * Ordinary members always use live database state even when sample mode is on.
 */
function coveted_member_sample_mode(array $user, ?PDO $pdo = null): bool
{
    if (!coveted_is_system_admin($user)) {
        return false;
    }

    return coveted_site_setting_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, false, $pdo);
}

function coveted_member_sample_event_start(int $daysAhead, int $hour, int $minute = 0): string
{
    $zone = new DateTimeZone('America/Phoenix');
    $utc = new DateTimeZone('UTC');
    $local = new DateTimeImmutable('now', $zone);
    $local = $local->modify('+' . max(1, $daysAhead) . ' days')->setTime($hour, $minute);

    return $local->setTimezone($utc)->format('Y-m-d H:i:s');
}

function coveted_member_sample_past_event_start(int $daysAgo, int $hour, int $minute = 0): string
{
    $zone = new DateTimeZone('America/Phoenix');
    $utc = new DateTimeZone('UTC');
    $local = new DateTimeImmutable('now', $zone);
    $local = $local->modify('-' . max(1, $daysAgo) . ' days')->setTime($hour, $minute);

    return $local->setTimezone($utc)->format('Y-m-d H:i:s');
}

/** @return array<string,mixed> */
function coveted_member_sample_data(): array
{
    $people = [
        ['id' => 'taylor-kim', 'name' => 'Taylor Kim', 'image' => '/assets/images/sample/people/taylor-kim.webp', 'context' => 'Met at Saturday Night Supper Club'],
        ['id' => 'jordan-ellis', 'name' => 'Jordan Ellis', 'image' => '/assets/images/sample/people/jordan-ellis.webp', 'context' => 'The Inner Circle'],
        ['id' => 'maya-rivera', 'name' => 'Maya Rivera', 'image' => '/assets/images/sample/people/maya-rivera.webp', 'context' => 'City Table Club'],
        ['id' => 'leo-martinez', 'name' => 'Leo Martinez', 'image' => '/assets/images/sample/people/leo-martinez.webp', 'context' => 'Met at Sunset Dinner'],
        ['id' => 'sienna-cole', 'name' => 'Sienna Cole', 'image' => '/assets/images/sample/people/sienna-cole.webp', 'context' => 'Late Night Listening'],
        ['id' => 'noah-bennett', 'name' => 'Noah Bennett', 'image' => '/assets/images/sample/people/noah-bennett.webp', 'context' => 'The Inner Circle'],
        ['id' => 'elena-park', 'name' => 'Elena Park', 'image' => '/assets/images/sample/people/elena-park.webp', 'context' => 'City Table Club'],
        ['id' => 'marcus-reed', 'name' => 'Marcus Reed', 'image' => '/assets/images/sample/people/marcus-reed.webp', 'context' => 'Met at Vinyl & Cocktails'],
        ['id' => 'ava-stone', 'name' => 'Ava Stone', 'image' => '/assets/images/sample/people/ava-stone.webp', 'context' => 'Late Night Listening'],
        ['id' => 'eli-thompson', 'name' => 'Eli Thompson', 'image' => '/assets/images/sample/people/eli-thompson.webp', 'context' => 'Phoenix Explorers'],
    ];

    $locations = [
        [
            'id' => 'ember-room',
            'name' => 'Ember Room',
            'city' => 'Phoenix, Arizona',
            'type' => 'Rooftop lounge',
            'image' => '/assets/images/sample/locations/ember-room/hero.webp',
        ],
        [
            'id' => 'harbor-house',
            'name' => 'Harbor House',
            'city' => 'Phoenix, Arizona',
            'type' => 'Dining room',
            'image' => '/assets/images/sample/locations/harbor-house/hero.webp',
        ],
        [
            'id' => 'velvet-note',
            'name' => 'Velvet Note',
            'city' => 'Phoenix, Arizona',
            'type' => 'Listening lounge',
            'image' => '/assets/images/sample/locations/velvet-note/hero.webp',
        ],
    ];

    $events = [
        [
            'public_id' => 'sample-saturday-night-supper-club',
            'title' => 'Saturday Night Supper Club',
            'event_type' => 'dinner',
            'timezone' => 'America/Phoenix',
            'starts_at' => coveted_member_sample_event_start(3, 19, 30),
            'location' => 'Ember Room',
            'city' => 'Phoenix, Arizona',
            'group' => 'The Inner Circle',
            'image' => '/assets/images/sample/events/saturday-night-supper-club-hero.webp',
            'description' => 'A long-table rooftop dinner with a small guest list and no agenda beyond a good night out.',
            'rsvp' => 'attending',
            'guest_count' => 18,
        ],
        [
            'public_id' => 'sample-sunset-dinner',
            'title' => 'Sunset Dinner',
            'event_type' => 'dinner',
            'timezone' => 'America/Phoenix',
            'starts_at' => coveted_member_sample_event_start(8, 18, 15),
            'location' => 'Harbor House',
            'city' => 'Phoenix, Arizona',
            'group' => 'City Table Club',
            'image' => '/assets/images/sample/events/sunset-dinner-hero.webp',
            'description' => 'An early-evening table built around conversation, shared plates and the last light of the day.',
            'rsvp' => 'invited',
            'guest_count' => 14,
        ],
        [
            'public_id' => 'sample-vinyl-and-cocktails',
            'title' => 'Vinyl & Cocktails',
            'event_type' => 'session',
            'timezone' => 'America/Phoenix',
            'starts_at' => coveted_member_sample_event_start(13, 20, 0),
            'location' => 'Velvet Note',
            'city' => 'Phoenix, Arizona',
            'group' => 'Late Night Listening',
            'image' => '/assets/images/sample/events/vinyl-and-cocktails-hero.webp',
            'description' => 'A low-light listening session with records, cocktails and a room designed for meeting people between songs.',
            'rsvp' => 'open',
            'guest_count' => 22,
        ],
    ];

    $groups = [
        [
            'id' => 'inner-circle',
            'name' => 'The Inner Circle',
            'members' => 28,
            'next' => 'Saturday Night Supper Club',
            'description' => 'Small-table dinners, thoughtful introductions and the kind of nights that make a second meeting easy.',
            'city' => 'Phoenix, Arizona',
            'image' => '/assets/images/sample/groups/the-inner-circle.webp',
        ],
        [
            'id' => 'city-table-club',
            'name' => 'City Table Club',
            'members' => 41,
            'next' => 'Sunset Dinner',
            'description' => 'A rotating table for people who like discovering good food and meeting someone new along the way.',
            'city' => 'Phoenix, Arizona',
            'image' => '/assets/images/sample/groups/city-table-club.webp',
        ],
        [
            'id' => 'late-night-listening',
            'name' => 'Late Night Listening',
            'members' => 33,
            'next' => 'Vinyl & Cocktails',
            'description' => 'Records, artists, low-light rooms and conversation between songs for people who would rather listen than scroll.',
            'city' => 'Phoenix, Arizona',
            'image' => '/assets/images/sample/groups/late-night-listening.webp',
        ],
    ];

    $benefits = [
        [
            'id' => 'dinner-on-us',
            'title' => 'Dinner on us',
            'partner' => 'Ember Room',
            'description' => 'A dining credit for your next return visit after Saturday Night Supper Club.',
            'value' => '$25 dining credit',
            'status' => 'Ready to use',
            'reward_type' => 'credit',
            'state' => 'inbox',
            'image' => '/assets/images/sample/benefits/dinner-on-us.webp',
        ],
        [
            'id' => 'member-gift',
            'title' => 'Member welcome',
            'partner' => 'Velvet Note',
            'description' => 'One house cocktail when you return for another listening night.',
            'value' => 'One house cocktail',
            'status' => 'Unlocked',
            'reward_type' => 'free_item',
            'state' => 'inbox',
            'image' => '/assets/images/sample/benefits/member-gift.webp',
        ],
        [
            'id' => 'dessert-for-two',
            'title' => 'Dessert for two',
            'partner' => 'Harbor House',
            'description' => 'A shared dessert added to your next dinner reservation.',
            'value' => 'Complimentary dessert',
            'status' => 'Ready to use',
            'reward_type' => 'perk',
            'state' => 'inbox',
            'image' => '/assets/images/sample/benefits/dinner-on-us.webp',
        ],
        [
            'id' => 'listening-room-pass',
            'title' => 'Listening room guest pass',
            'partner' => 'Velvet Note',
            'description' => 'A guest-access reward from a previous listening session.',
            'value' => 'Guest admission',
            'status' => 'Redeemed',
            'reward_type' => 'access',
            'state' => 'claimed',
            'image' => '/assets/images/sample/benefits/member-gift.webp',
        ],
    ];

    $reconnectEvents = [
        [
            'public_id' => 'sample-first-friday-supper',
            'title' => 'First Friday Supper',
            'starts_at' => coveted_member_sample_past_event_start(6, 19, 0),
            'group_id' => 'inner-circle',
            'group' => 'The Inner Circle',
            'location' => 'Ember Room',
            'image' => '/assets/images/sample/events/saturday-night-supper-club-hero.webp',
        ],
        [
            'public_id' => 'sample-listening-room-night',
            'title' => 'Listening Room Night',
            'starts_at' => coveted_member_sample_past_event_start(15, 20, 0),
            'group_id' => 'late-night-listening',
            'group' => 'Late Night Listening',
            'location' => 'Velvet Note',
            'image' => '/assets/images/sample/events/vinyl-and-cocktails-hero.webp',
        ],
    ];

    $reconnects = [
        $people[1] + ['event_public_id' => 'sample-first-friday-supper', 'status' => 'mutual'],
        $people[2] + ['event_public_id' => 'sample-first-friday-supper', 'status' => 'pending'],
        $people[4] + ['event_public_id' => 'sample-first-friday-supper', 'status' => ''],
        $people[3] + ['event_public_id' => 'sample-listening-room-night', 'status' => 'mutual'],
        $people[5] + ['event_public_id' => 'sample-listening-room-night', 'status' => ''],
        $people[0] + ['event_public_id' => 'sample-listening-room-night', 'status' => ''],
    ];

    $profile = [
        'display_name' => 'Taylor Kim',
        'city' => 'Phoenix, Arizona',
        'bio' => 'Phoenix-based product designer who likes good food, live music, small rooms and meeting people through something worth leaving the house for.',
        'avatar_url' => '/assets/images/sample/people/taylor-kim.webp',
        'cover_url' => '/assets/images/sample/events/saturday-night-supper-club-hero.webp',
        'interests' => ['Local dining', 'Live music', 'Design', 'Travel', 'Independent venues'],
        'gathering_styles' => ['Dinner table', 'Listening night', 'Mystery gathering'],
    ];

    return [
        'people' => $people,
        'locations' => $locations,
        'events' => $events,
        'groups' => $groups,
        'benefits' => $benefits,
        'reconnect_events' => $reconnectEvents,
        'reconnects' => $reconnects,
        'profile' => $profile,
    ];
}
