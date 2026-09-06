<?php
declare(strict_types=1);

require_once __DIR__ . '/app/business_host_workspace.php';

$user = coveted_require_user();

if (!coveted_business_host_has_access($user)) {
    http_response_code(403);
    coveted_page_start('Business Host', 'Events');
    ?>
    <section class="cv-page-heading">
        <span class="cv-eyebrow">BUSINESS / LOCATION HOST</span>
        <h1>Business Host access required.</h1>
        <p>This workspace is available to Coveted System Admins and accounts assigned as a Business Admin.</p>
    </section>
    <div class="cv-card cv-empty">
        <h2>No venue workspace is assigned.</h2>
        <p>Coveted Admin assigns Business Admin access when a venue or location is ready to operate events.</p>
        <a class="cv-button" href="/notifications.php">Open Notifications</a>
    </div>
    <?php
    coveted_page_end();
    exit;
}

$businesses = coveted_business_host_businesses($user);
$requestedBusinessRef = trim((string)($_GET['business'] ?? $_POST['business_ref'] ?? ''));
$requestedEventRef = trim((string)($_GET['event'] ?? $_POST['event_ref'] ?? ''));
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$error = '';
$optionalWarning = '';
$saved = trim((string)($_GET['saved'] ?? ''));
$notice = match ($saved) {
    'attendance' => 'Attendance updated.',
    'issue' => 'Issue sent to Coveted Admin.',
    default => '',
};
$business = null;

try {
    $business = coveted_business_host_resolve_business($user, $requestedBusinessRef);
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
    if (!$isPost) {
        $business = $businesses[0] ?? null;
    }
}

if ($isPost) {
    coveted_require_csrf();

    try {
        if (!$business) {
            throw new InvalidArgumentException('Choose a valid business first.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if (!in_array($action, ['record_attendance','report_issue'], true)) {
            throw new InvalidArgumentException('Unsupported Business Host action.');
        }

        $eventRef = trim((string)($_POST['event_ref'] ?? ''));
        if ($eventRef === '') {
            throw new InvalidArgumentException('Choose a valid event.');
        }

        if ($action === 'record_attendance') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $attendanceStatus = trim((string)($_POST['attendance_status'] ?? ''));
            if ($userId < 1) {
                throw new InvalidArgumentException('Choose a valid guest.');
            }

            coveted_business_host_record_attendance(
                $user,
                (int)$business['id'],
                $eventRef,
                $userId,
                $attendanceStatus
            );

            coveted_redirect(
                '/business-host.php?business=' . rawurlencode((string)$business['public_id'])
                . '&event=' . rawurlencode($eventRef)
                . '&saved=attendance#event-day'
            );
        }

        coveted_business_host_report_issue(
            $user,
            (int)$business['id'],
            $eventRef,
            (string)($_POST['issue_category'] ?? ''),
            (string)($_POST['issue_message'] ?? '')
        );
        coveted_redirect(
            '/business-host.php?business=' . rawurlencode((string)$business['public_id'])
            . '&event=' . rawurlencode($eventRef)
            . '&saved=issue#admin-coordination'
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        $requestedEventRef = trim((string)($_POST['event_ref'] ?? $requestedEventRef));
    } catch (Throwable $e) {
        error_log('Coveted Business Host Workspace error: ' . $e->getMessage());
        $error = 'Unable to complete that venue action right now.';
        $requestedEventRef = trim((string)($_POST['event_ref'] ?? $requestedEventRef));
    }
}

$events = [];
$locations = [];
if ($business) {
    try {
        $events = coveted_business_host_events($user, (int)$business['id'], 250);
        $locations = coveted_locations_for_business((int)$business['id']);
    } catch (Throwable $e) {
        error_log('Coveted Business Host data load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load venue events right now.';
    }
}

$now = time();
$upcomingEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() >= $now
        && !in_array((string)$event['status'], ['completed','cancelled'], true)
));
$pastEvents = array_values(array_filter(
    $events,
    static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() < $now
        || in_array((string)$event['status'], ['completed','cancelled'], true)
));
usort($upcomingEvents, static fn(array $a, array $b): int => strcmp((string)$a['starts_at'], (string)$b['starts_at']));
usort($pastEvents, static fn(array $a, array $b): int => strcmp((string)$b['starts_at'], (string)$a['starts_at']));
$orderedEvents = array_merge($upcomingEvents, $pastEvents);

$selectedEvent = null;
if ($business && $requestedEventRef !== '') {
    try {
        $selectedEvent = coveted_business_host_event($user, (int)$business['id'], $requestedEventRef);
        if (!$selectedEvent) {
            $error = $error !== '' ? $error : 'That event is not assigned to this business location.';
        }
    } catch (InvalidArgumentException $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }
}
$selectedEvent ??= $orderedEvents[0] ?? null;

$guests = [];
$campaigns = [];
$artists = [];
if ($business && $selectedEvent) {
    try {
        $guests = coveted_business_host_guests($user, (int)$business['id'], (int)$selectedEvent['id']);
    } catch (Throwable $e) {
        error_log('Coveted Business Host guest load error: ' . $e->getMessage());
        $optionalWarning = 'Some event-day details are temporarily unavailable.';
    }
    try {
        $campaigns = coveted_business_host_campaigns($user, (int)$business['id'], (int)$selectedEvent['id']);
    } catch (Throwable $e) {
        error_log('Coveted Business Host campaign load error: ' . $e->getMessage());
        $optionalWarning = 'Some event-day details are temporarily unavailable.';
    }
    try {
        $artists = coveted_business_host_artists($user, (int)$business['id'], (int)$selectedEvent['id']);
    } catch (Throwable $e) {
        error_log('Coveted Business Host artist load error: ' . $e->getMessage());
        $optionalWarning = 'Some event-day details are temporarily unavailable.';
    }
}

$canCheckin = $selectedEvent ? coveted_business_host_can_checkin($user, $selectedEvent) : false;
$expectedCount = $selectedEvent ? coveted_business_host_expected_count($selectedEvent) : 0;
$attendanceRate = $selectedEvent ? coveted_business_host_attendance_rate($selectedEvent) : null;
$selectedLocation = null;
if ($selectedEvent) {
    foreach ($locations as $location) {
        if ((int)$location['id'] === (int)$selectedEvent['location_id']) {
            $selectedLocation = $location;
            break;
        }
    }
}

$businessRef = $business ? (string)$business['public_id'] : '';
$eventRef = $selectedEvent ? (string)$selectedEvent['public_id'] : '';
$sponsorshipHref = $business
    ? '/business-sponsorships.php?business=' . rawurlencode($businessRef)
        . ($eventRef !== '' ? '&event=' . rawurlencode($eventRef) : '')
    : '/business-sponsorships.php';
$selectedEventIsPast = $selectedEvent
    ? coveted_utc_datetime((string)$selectedEvent['starts_at'])->getTimestamp() < $now
        || in_array((string)$selectedEvent['status'], ['completed','cancelled'], true)
    : false;

coveted_page_start('Business Host', 'Events');
?>
<div class="cv-business-host">
    <section class="cv-business-host-hero">
        <div>
            <span class="cv-eyebrow">BUSINESS / LOCATION HOST</span>
            <h1><?= $business ? coveted_e((string)$business['name']) : 'Venue operations, one place.' ?></h1>
            <p>Prepare the venue, see assigned event details, operate approved check-in access, review rewards and artist plans, and close the loop after the gathering. Coveted System Admin owns event creation and configuration.</p>
        </div>
        <?php if (count($businesses) > 1): ?>
            <form class="cv-business-host-switcher" method="get" action="/business-host.php">
                <label for="business-host-switcher">Business</label>
                <select id="business-host-switcher" name="business">
                    <?php foreach ($businesses as $availableBusiness): ?>
                        <option value="<?= coveted_e((string)$availableBusiness['public_id']) ?>" <?= $business && (int)$availableBusiness['id'] === (int)$business['id'] ? 'selected' : '' ?>><?= coveted_e((string)$availableBusiness['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="cv-button cv-button-soft" type="submit">Switch workspace</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($notice !== ''): ?><div class="cv-business-host-notice" role="status"><?= coveted_e($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-business-host-error" role="alert"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($optionalWarning !== ''): ?><div class="cv-business-host-error" role="status"><?= coveted_e($optionalWarning) ?></div><?php endif; ?>

    <section class="cv-business-host-banner">
        <div>
            <strong>Admin-controlled event setup</strong>
            <p>Venue hosts can operate the event they are given. Timing, location assignment, lineup, audience, capacity, event status and campaign configuration remain with Coveted System Admin.</p>
        </div>
        <?php if ($business): ?>
            <div class="cv-business-host-actions">
                <a class="cv-button cv-button-soft" href="<?= coveted_e($sponsorshipHref) ?>">Benefits / Sponsorship</a>
                <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">Business Profile</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="cv-business-host-stats" aria-label="Venue overview">
        <div class="cv-business-host-stat"><span>Upcoming events</span><strong><?= count($upcomingEvents) ?></strong></div>
        <div class="cv-business-host-stat"><span>Expected selected event</span><strong><?= $selectedEvent ? $expectedCount : 0 ?></strong></div>
        <div class="cv-business-host-stat"><span>Checked in / attended</span><strong><?= $selectedEvent ? (int)$selectedEvent['attendance_count'] : 0 ?></strong></div>
        <div class="cv-business-host-stat"><span>Event rewards</span><strong><?= count($campaigns) ?></strong></div>
    </section>

    <?php if (!$business): ?>
        <div class="cv-business-host-empty">
            <h2>No business is assigned yet.</h2>
            <p>Coveted Admin must assign this account to a business before the venue workspace can open.</p>
        </div>
    <?php else: ?>
        <div class="cv-business-host-layout">
            <aside class="cv-business-host-sidebar">
                <span class="cv-eyebrow">VENUE EVENTS</span>
                <h2>Assigned to this location</h2>
                <?php if (!$orderedEvents): ?>
                    <div class="cv-business-host-empty">
                        <p>No venue events are assigned yet. Coveted Admin will place events here by assigning one of this business’s locations.</p>
                    </div>
                <?php else: ?>
                    <div class="cv-business-host-event-list">
                        <?php foreach ($orderedEvents as $event): ?>
                            <?php $eventSelected = $selectedEvent && (int)$event['id'] === (int)$selectedEvent['id']; ?>
                            <a class="cv-business-host-event-link <?= $eventSelected ? 'is-active' : '' ?>" href="/business-host.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">
                                <strong><?= coveted_e((string)$event['title']) ?></strong>
                                <span><?= coveted_e(coveted_event_format($event, 'M j · g:i A')) ?></span>
                                <small><?= coveted_e((string)$event['location_name']) ?> · <?= coveted_e(ucwords(str_replace('_', ' ', (string)$event['status']))) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>

            <div class="cv-business-host-main">
                <?php if (!$selectedEvent): ?>
                    <section class="cv-business-host-panel">
                        <div class="cv-business-host-empty">
                            <h2>No event selected.</h2>
                            <p>Once Coveted Admin assigns an event to one of your business locations, its operating workspace will appear here.</p>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="cv-business-host-panel">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow">HOST HOME</span>
                                <h2><?= coveted_e((string)$selectedEvent['title']) ?></h2>
                                <p><?= coveted_e((string)($selectedEvent['description'] ?? '')) ?></p>
                            </div>
                            <span class="cv-business-host-pill <?= $selectedEvent['status'] === 'published' ? 'is-live' : '' ?>"><?= coveted_e(ucwords(str_replace('_', ' ', (string)$selectedEvent['status']))) ?></span>
                        </div>
                        <div class="cv-business-host-detail-grid">
                            <div class="cv-business-host-detail"><span>When</span><strong><?= coveted_e(coveted_event_format($selectedEvent, 'D, M j · g:i A')) ?></strong></div>
                            <div class="cv-business-host-detail"><span>Venue</span><strong><?= coveted_e((string)$selectedEvent['location_name']) ?></strong></div>
                            <div class="cv-business-host-detail"><span>Community</span><strong><?= coveted_e((string)$selectedEvent['group_name']) ?></strong></div>
                            <div class="cv-business-host-detail"><span>Expected</span><strong><?= $expectedCount ?> people</strong></div>
                            <div class="cv-business-host-detail"><span>Capacity</span><strong><?= $selectedEvent['capacity'] !== null ? (int)$selectedEvent['capacity'] : 'Open' ?></strong></div>
                            <div class="cv-business-host-detail"><span>Your event role</span><strong><?= $canCheckin ? coveted_e(ucwords(str_replace('_', ' ', (string)($selectedEvent['actor_host_role'] ?: 'System Admin')))) : 'Venue operator' ?></strong></div>
                        </div>
                    </section>

                    <nav class="cv-business-host-section-nav" aria-label="Business Host workspace sections">
                        <a href="#event-day">Event Day</a>
                        <a href="#venue">Venue Profile</a>
                        <a href="#rewards">Rewards &amp; Perks</a>
                        <a href="<?= coveted_e($sponsorshipHref) ?>">Benefits / Sponsorship</a>
                        <a href="#entertainment">Artist / Entertainment</a>
                        <a href="#report">Post-Event Report</a>
                        <a href="#admin-coordination">Admin Coordination</a>
                    </nav>

                    <section class="cv-business-host-panel" id="event-day">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow">EVENT DAY MODE</span>
                                <h2>Guest operations</h2>
                                <p><?= $canCheckin ? 'Check-in access is active for this account on this event.' : 'Guest visibility is available for venue preparation. Attendance controls require an explicit event check-in assignment from Coveted Admin.' ?></p>
                            </div>
                            <span class="cv-business-host-pill <?= $canCheckin ? 'is-live' : '' ?>"><?= $canCheckin ? 'Check-in enabled' : 'View only' ?></span>
                        </div>

                        <div class="cv-business-host-report">
                            <div><strong><?= $expectedCount ?></strong><span>Expected incl. +1s</span></div>
                            <div><strong><?= (int)$selectedEvent['waitlist_count'] ?></strong><span>Waitlist</span></div>
                            <div><strong><?= (int)$selectedEvent['attendance_count'] ?></strong><span>Arrived / attended</span></div>
                        </div>

                        <?php if (!$canCheckin): ?>
                            <div class="cv-business-host-notice">Coveted Admin must assign this account the event’s <strong>Check-in</strong> role before attendance controls unlock. Business Admin access by itself does not grant check-in authority.</div>
                        <?php endif; ?>

                        <?php if (!$guests): ?>
                            <div class="cv-business-host-empty"><p>No attending or waitlisted guests are currently on this event.</p></div>
                        <?php else: ?>
                            <div class="cv-business-host-guest-list">
                                <?php foreach ($guests as $guest): ?>
                                    <?php
                                    $avatar = coveted_safe_url((string)($guest['avatar_url'] ?? ''), true);
                                    $attendanceStatus = trim((string)($guest['attendance_status'] ?? ''));
                                    $guestName = trim((string)$guest['display_name']) ?: 'Member';
                                    ?>
                                    <article class="cv-business-host-guest">
                                        <div class="cv-business-host-guest-copy">
                                            <?php if ($avatar !== null): ?>
                                                <img src="<?= coveted_e($avatar) ?>" alt="" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <span class="cv-business-host-avatar-placeholder"><?= coveted_e(coveted_shell_initials($guestName)) ?></span>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?= coveted_e($guestName) ?></strong>
                                                <span><?= coveted_e(ucfirst((string)$guest['response'])) ?><?= (int)$guest['guest_count'] > 0 ? ' · +' . (int)$guest['guest_count'] : '' ?><?= $attendanceStatus !== '' ? ' · ' . coveted_e(ucwords(str_replace('_', ' ', $attendanceStatus))) : '' ?></span>
                                            </div>
                                        </div>
                                        <?php if ($canCheckin && (string)$guest['response'] === 'attending'): ?>
                                            <div class="cv-business-host-attendance-actions" aria-label="Attendance actions for <?= coveted_e($guestName) ?>">
                                                <?php foreach (['checked_in' => 'Check in', 'attended' => 'Attended', 'left_early' => 'Left early', 'no_show' => 'No show'] as $statusValue => $statusLabel): ?>
                                                    <form method="post" action="/business-host.php">
                                                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="record_attendance">
                                                        <input type="hidden" name="business_ref" value="<?= coveted_e($businessRef) ?>">
                                                        <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                                        <input type="hidden" name="user_id" value="<?= (int)$guest['user_id'] ?>">
                                                        <input type="hidden" name="attendance_status" value="<?= coveted_e($statusValue) ?>">
                                                        <button type="submit"><?= coveted_e($statusLabel) ?></button>
                                                    </form>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="cv-business-host-panel" id="venue">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow">VENUE PROFILE</span>
                                <h2><?= coveted_e((string)$selectedEvent['location_name']) ?></h2>
                                <p>Location information used by Coveted Admin when assigning this venue to gatherings.</p>
                            </div>
                            <span class="cv-business-host-pill"><?= coveted_e(ucfirst((string)$selectedEvent['location_status'])) ?></span>
                        </div>
                        <div class="cv-business-host-grid">
                            <div>
                                <h3>Address</h3>
                                <address class="cv-business-host-address">
                                    <?= coveted_e((string)($selectedEvent['address1'] ?? '')) ?><br>
                                    <?php if (!empty($selectedEvent['address2'])): ?><?= coveted_e((string)$selectedEvent['address2']) ?><br><?php endif; ?>
                                    <?= coveted_e(trim((string)($selectedEvent['city'] ?? '') . ', ' . (string)($selectedEvent['region'] ?? '') . ' ' . (string)($selectedEvent['postal_code'] ?? ''))) ?><br>
                                    <?= coveted_e((string)($selectedEvent['country'] ?? '')) ?>
                                </address>
                            </div>
                            <div>
                                <h3>Operating details</h3>
                                <div class="cv-business-host-list">
                                    <div class="cv-business-host-item"><strong>Timezone</strong><p><?= coveted_e((string)$selectedEvent['location_timezone']) ?></p></div>
                                    <div class="cv-business-host-item"><strong>Location capacity</strong><p><?= $selectedLocation && $selectedLocation['capacity'] !== null ? (int)$selectedLocation['capacity'] . ' people' : 'Not set' ?></p></div>
                                </div>
                            </div>
                        </div>
                        <div class="cv-business-host-actions">
                            <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">Manage Business Profile</a>
                        </div>
                    </section>

                    <section class="cv-business-host-panel" id="rewards">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow">REWARDS &amp; PERKS</span>
                                <h2>What is attached to this gathering</h2>
                                <p>View-only event reward context for venue operations. Coveted Admin controls event campaign configuration. A business may propose sponsored value separately; proposals still require Coveted review.</p>
                            </div>
                            <span class="cv-business-host-pill"><?= count($campaigns) ?> linked</span>
                        </div>
                        <?php if (!$campaigns): ?>
                            <div class="cv-business-host-empty"><p>No campaigns or rewards are currently linked to this event.</p></div>
                        <?php else: ?>
                            <div class="cv-business-host-list">
                                <?php foreach ($campaigns as $campaign): ?>
                                    <article class="cv-business-host-item">
                                        <strong><?= coveted_e((string)$campaign['title']) ?></strong>
                                        <p><?= coveted_e((string)$campaign['reward_title']) ?> · <?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['reward_type']))) ?> · <?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['status']))) ?></p>
                                        <p>Trigger: <?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['trigger_key']))) ?> · Claim: <?= coveted_e(ucwords(str_replace('_', ' ', (string)$campaign['claim_mode']))) ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="cv-business-host-actions">
                            <a class="cv-button cv-button-soft" href="<?= coveted_e($sponsorshipHref) ?>">Propose Sponsored Benefit / View ROI</a>
                        </div>
                    </section>

                    <section class="cv-business-host-panel" id="entertainment">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow">ARTIST / ENTERTAINMENT</span>
                                <h2>Appearance plan</h2>
                                <p>Artist assignments are set by Coveted Admin and surfaced here for venue readiness.</p>
                            </div>
                            <span class="cv-business-host-pill"><?= count($artists) ?> assigned</span>
                        </div>
                        <?php if (!$artists): ?>
                            <div class="cv-business-host-empty"><p>No artist or entertainment partner is assigned to this event.</p></div>
                        <?php else: ?>
                            <div class="cv-business-host-list">
                                <?php foreach ($artists as $artist): ?>
                                    <article class="cv-business-host-item">
                                        <strong><?= coveted_e((string)$artist['artist_name']) ?></strong>
                                        <p><?= coveted_e(ucwords(str_replace('_', ' ', (string)$artist['appearance_type']))) ?> appearance</p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="cv-business-host-panel" id="report">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow"><?= $selectedEventIsPast ? 'POST-EVENT REPORT' : 'EVENT SNAPSHOT' ?></span>
                                <h2><?= $selectedEventIsPast ? 'How the room performed' : 'What the room looks like right now' ?></h2>
                                <p><?= $selectedEventIsPast ? 'Attendance outcomes are calculated from Coveted’s canonical event records.' : 'This remains a live operational snapshot until the event is complete.' ?></p>
                            </div>
                        </div>
                        <div class="cv-business-host-report">
                            <div><strong><?= $expectedCount ?></strong><span>Expected</span></div>
                            <div><strong><?= (int)$selectedEvent['attendance_count'] ?></strong><span>Arrived / attended</span></div>
                            <div><strong><?= (int)$selectedEvent['no_show_count'] ?></strong><span>No-shows</span></div>
                            <div><strong><?= $attendanceRate !== null ? $attendanceRate . '%' : '—' ?></strong><span>Attendance rate</span></div>
                            <div><strong><?= (int)$selectedEvent['waitlist_count'] ?></strong><span>Waitlist</span></div>
                            <div><strong><?= count($campaigns) ?></strong><span>Linked rewards</span></div>
                        </div>
                    </section>

                    <section class="cv-business-host-panel" id="admin-coordination">
                        <div class="cv-business-host-panel-head">
                            <div>
                                <span class="cv-eyebrow">GUEST ISSUES &amp; ADMIN COORDINATION</span>
                                <h2>Flag an operational issue without changing event setup</h2>
                                <p>Send guest, venue, timing, reward, artist or safety issues to active Coveted System Admins. Venue hosts still cannot change event timing, location assignment, audience, capacity, lineup, reward setup or member eligibility here.</p>
                            </div>
                        </div>
                        <div class="cv-business-host-grid">
                            <form class="cv-business-host-issue-form" method="post" action="/business-host.php">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="report_issue">
                                <input type="hidden" name="business_ref" value="<?= coveted_e($businessRef) ?>">
                                <input type="hidden" name="event_ref" value="<?= coveted_e($eventRef) ?>">
                                <label for="business-host-issue-category">Issue type</label>
                                <select id="business-host-issue-category" name="issue_category" required>
                                    <option value="guest">Guest issue</option>
                                    <option value="venue">Venue / operations</option>
                                    <option value="timing">Timing / schedule</option>
                                    <option value="reward">Reward / perk</option>
                                    <option value="artist">Artist / entertainment</option>
                                    <option value="safety">Safety / urgent</option>
                                    <option value="other">Other</option>
                                </select>
                                <label for="business-host-issue-message">What does Admin need to know?</label>
                                <textarea id="business-host-issue-message" name="issue_message" maxlength="1500" rows="5" required></textarea>
                                <button class="cv-button cv-button-primary" type="submit">Send to Coveted Admin</button>
                            </form>
                            <div class="cv-business-host-list">
                                <div class="cv-business-host-item">
                                    <strong>What happens next</strong>
                                    <p>The report is written through Coveted’s canonical notification service to active System Admins. Safety reports are sent at high priority.</p>
                                </div>
                                <div class="cv-business-host-item">
                                    <strong>Configuration stays protected</strong>
                                    <p>Reporting an issue does not modify the event, RSVP eligibility, rewards, artist lineup or venue assignment.</p>
                                </div>
                            </div>
                        </div>
                        <div class="cv-business-host-actions">
                            <a class="cv-button cv-button-soft" href="/notifications.php">Open Notifications</a>
                            <a class="cv-button cv-button-soft" href="<?= coveted_e($sponsorshipHref) ?>">Benefits / Sponsorship</a>
                            <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">Business Profile</a>
                            <?php if (coveted_is_system_admin($user)): ?>
                                <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode($eventRef)) ?>">Open Event Admin</a>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
