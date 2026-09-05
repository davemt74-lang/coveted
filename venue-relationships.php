<?php
declare(strict_types=1);

require_once __DIR__ . '/app/venue_relationships.php';

$user = coveted_require_user();
$error = '';
$notice = trim((string)($_SESSION['venue_relationship_notice'] ?? ''));
unset($_SESSION['venue_relationship_notice']);

$requestedBusiness = trim((string)($_GET['business'] ?? $_POST['business'] ?? ''));
$requestedGroup = trim((string)($_GET['group'] ?? $_POST['group'] ?? ''));
$requestedLocation = trim((string)($_GET['location'] ?? $_POST['location'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $business = coveted_business_resolve_context($user, $requestedBusiness);
        if (!$business) {
            throw new InvalidArgumentException('Business Admin access is required.');
        }

        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'update_relationship') {
            throw new InvalidArgumentException('Unsupported venue relationship action.');
        }

        $changed = coveted_venue_relationship_update(
            $user,
            (int)$business['id'],
            $requestedGroup,
            $requestedLocation,
            $_POST
        );

        $_SESSION['venue_relationship_notice'] = $changed
            ? 'Venue relationship updated.'
            : 'Venue relationship is already up to date.';

        coveted_redirect(
            '/venue-relationships.php?business=' . rawurlencode((string)$business['public_id'])
            . '&group=' . rawurlencode($requestedGroup)
            . '&location=' . rawurlencode($requestedLocation)
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted venue relationship update error: ' . $e->getMessage());
        $error = 'Unable to update that venue relationship right now.';
    }
}

$businesses = coveted_businesses_for_actor($user);
$business = null;
try {
    $business = coveted_business_resolve_context($user, $requestedBusiness);
} catch (InvalidArgumentException $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$relationships = [];
if ($business) {
    try {
        $relationships = coveted_venue_relationships_for_business($user, (int)$business['id']);
    } catch (Throwable $e) {
        error_log('Coveted venue relationship load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load venue relationships right now.';
    }
}

$selected = null;
foreach ($relationships as $relationship) {
    if (
        $requestedGroup !== ''
        && $requestedLocation !== ''
        && (
            hash_equals((string)$relationship['group_public_id'], $requestedGroup)
            || (string)$relationship['group_id'] === $requestedGroup
        )
        && (
            hash_equals((string)$relationship['location_public_id'], $requestedLocation)
            || (string)$relationship['location_id'] === $requestedLocation
        )
    ) {
        $selected = $relationship;
        break;
    }
}

$events = [];
if ($business && $selected) {
    try {
        $events = coveted_venue_relationship_events(
            $user,
            (int)$business['id'],
            (string)$selected['group_public_id'],
            (string)$selected['location_public_id']
        );
    } catch (Throwable $e) {
        error_log('Coveted venue relationship event history error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load relationship event history right now.';
    }
}

$statusLabels = [
    'new' => 'New',
    'event_venue' => 'Event Venue',
    'partner' => 'Partner',
    'preferred_partner' => 'Preferred Partner',
    'home_venue' => 'Home Venue',
];

$relationshipCount = count($relationships);
$partnerCount = count(array_filter(
    $relationships,
    static fn(array $relationship): bool => in_array(
        (string)$relationship['relationship_status'],
        ['partner', 'preferred_partner', 'home_venue'],
        true
    )
));
$completedEventCount = array_sum(array_map(
    static fn(array $relationship): int => (int)$relationship['completed_events'],
    $relationships
));
$verifiedVisitCount = array_sum(array_map(
    static fn(array $relationship): int => (int)$relationship['verified_visits'],
    $relationships
));
$returnClaimCount = array_sum(array_map(
    static fn(array $relationship): int => (int)$relationship['return_claims'],
    $relationships
));

$formatTime = static function (?string $value, string $timezone = 'UTC'): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    try {
        $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }

    return coveted_utc_datetime($value)->setTimezone($zone)->format('M j, Y · g:i A');
};

coveted_page_start('Venue Relationships');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">VENUE RELATIONSHIPS</span>
    <h1><?= $business ? coveted_e($business['name']) : 'Relationship intelligence' ?></h1>
    <p>Measure the relationship created by real gatherings: attendance delivered, repeat members, business benefits, claims and explicit return activity.</p>
</section>

<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if (!$business): ?>
    <div class="cv-card cv-empty">
        <h2>No business workspace is available.</h2>
        <p>Venue relationship intelligence is available only to a scoped Business Admin or Coveted System Admin.</p>
        <a class="cv-button cv-button-soft" href="/profile.php">Back to Profile</a>
    </div>
    <?php coveted_page_end(); exit; ?>
<?php endif; ?>

<?php
$businessRef = (string)$business['public_id'];
?>
<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">CURRENT BUSINESS</span>
        <h2><?= coveted_e($business['name']) ?></h2>
    </div>
    <div class="cv-member-actions">
        <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">Business Workspace</a>
        <?php if (count($businesses) > 1): ?>
            <form class="cv-business-selector" method="get">
                <label>
                    <span>Switch business</span>
                    <select name="business" data-submit-on-change>
                        <?php foreach ($businesses as $item): ?>
                            <option value="<?= coveted_e($item['public_id']) ?>" <?= (int)$item['id'] === (int)$business['id'] ? 'selected' : '' ?>><?= coveted_e($item['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="cv-stat-grid cv-home-stats" aria-label="Venue relationship summary">
    <div class="cv-card cv-stat"><strong><?= $relationshipCount ?></strong><span>Group / venue relationships</span></div>
    <div class="cv-card cv-stat"><strong><?= $partnerCount ?></strong><span>Partner relationships</span></div>
    <div class="cv-card cv-stat"><strong><?= $completedEventCount ?></strong><span>Completed gatherings</span></div>
    <div class="cv-card cv-stat"><strong><?= $verifiedVisitCount ?></strong><span>Verified member visits</span></div>
</section>

<article class="cv-card cv-feature-card cv-copy-card">
    <span class="cv-kicker">RELATIONSHIP, NOT AD INVENTORY</span>
    <h2>A gathering is the beginning of the venue relationship.</h2>
    <p>Coveted follows the measurable chain after a group walks through the door: repeat attendance, business-owned benefits, verified claims and return/guest-return campaign activity. Individual attendee answers and private member relationship data are not exposed here.</p>
    <div class="cv-tag-row">
        <span class="cv-pill"><?= $returnClaimCount ?> return-linked claims</span>
        <span class="cv-pill">Aggregate member data only</span>
        <span class="cv-pill">Canonical campaign attribution</span>
    </div>
</article>

<?php if ($selected): ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">RELATIONSHIP DETAIL</span>
            <h2><?= coveted_e($selected['group_name']) ?> × <?= coveted_e($selected['location_name']) ?></h2>
        </div>
        <a class="cv-button cv-button-soft" href="/venue-relationships.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">All Relationships</a>
    </div>

    <section class="cv-two-column">
        <div class="cv-stack">
            <article class="cv-card cv-copy-card">
                <div class="cv-tag-row">
                    <span class="cv-status"><?= coveted_e($statusLabels[(string)$selected['relationship_status']] ?? 'Relationship') ?></span>
                    <?php if ((int)$selected['benefits_enabled'] === 1): ?><span class="cv-pill">Partner benefits enabled</span><?php endif; ?>
                    <?php if ((int)$selected['mystery_events_enabled'] === 1): ?><span class="cv-pill">Mystery events enabled</span><?php endif; ?>
                </div>
                <h2><?= coveted_e($selected['group_name']) ?></h2>
                <p><?= coveted_e($selected['location_name']) ?><?php if (!empty($selected['city']) || !empty($selected['region'])): ?> · <?= coveted_e(trim((string)$selected['city'] . (!empty($selected['city']) && !empty($selected['region']) ? ', ' : '') . (string)$selected['region'])) ?><?php endif; ?></p>
                <div class="cv-meta-row">
                    <span><?= (int)$selected['completed_events'] ?> completed events</span>
                    <span><?= (int)$selected['verified_visits'] ?> verified visits</span>
                    <span><?= (int)$selected['unique_attendees'] ?> unique attendees</span>
                    <span><?= (int)$selected['repeat_attendees'] ?> repeat attendees</span>
                </div>
                <?php if (!empty($selected['notes'])): ?><p><?= nl2br(coveted_e((string)$selected['notes'])) ?></p><?php endif; ?>
            </article>

            <section class="cv-stat-grid" aria-label="Relationship value signals">
                <div class="cv-card cv-stat"><strong><?= (int)$selected['business_benefits_issued'] ?></strong><span>Business benefits issued</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$selected['claims'] ?></strong><span>Claims</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$selected['return_claims'] ?></strong><span>Return-linked claims</span></div>
                <div class="cv-card cv-stat"><strong><?= (int)$selected['guest_return_claims'] ?></strong><span>Guest-return claims</span></div>
            </section>
        </div>

        <aside class="cv-stack">
            <form class="cv-card cv-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="update_relationship">
                <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                <input type="hidden" name="group" value="<?= coveted_e($selected['group_public_id']) ?>">
                <input type="hidden" name="location" value="<?= coveted_e($selected['location_public_id']) ?>">
                <span class="cv-eyebrow">PARTNER STATE</span>
                <h2>Manage the relationship</h2>
                <p>Status is intentional. Coveted never auto-promotes a venue because a metric crossed an arbitrary threshold.</p>
                <label>Relationship status
                    <select name="relationship_status">
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= coveted_e($value) ?>" <?= (string)$selected['relationship_status'] === $value ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="cv-check-row"><input type="checkbox" name="benefits_enabled" value="1" <?= (int)$selected['benefits_enabled'] === 1 ? 'checked' : '' ?>> Partner benefits enabled</label>
                <label class="cv-check-row"><input type="checkbox" name="mystery_events_enabled" value="1" <?= (int)$selected['mystery_events_enabled'] === 1 ? 'checked' : '' ?>> Mystery events enabled</label>
                <label>Internal relationship notes<textarea name="notes" maxlength="4000" rows="5"><?= coveted_e((string)($selected['notes'] ?? '')) ?></textarea></label>
                <button class="cv-button cv-button-primary" type="submit">Save Relationship</button>
            </form>
        </aside>
    </section>

    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">EVENT HISTORY</span>
            <h2>What this relationship has actually delivered</h2>
        </div>
    </div>

    <section class="cv-card cv-table-card">
        <?php if (!$events): ?>
            <div class="cv-empty"><h2>No event history.</h2><p>This relationship exists only when Coveted has a real event connection at the venue.</p></div>
        <?php else: ?>
            <div class="cv-table-wrap"><table class="cv-table">
                <thead><tr><th>Event</th><th>Status</th><th>Verified attendance</th><th>Business benefits</th><th>Claims</th><th>Refunds</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><strong><?= coveted_e($event['title']) ?></strong><br><small><?= coveted_e($formatTime((string)$event['starts_at'], (string)$event['timezone'])) ?></small></td>
                        <td><span class="cv-status"><?= coveted_e(ucfirst((string)$event['status'])) ?></span></td>
                        <td><?= (int)$event['verified_attendance'] ?></td>
                        <td><?= (int)$event['business_benefits_issued'] ?></td>
                        <td><?= (int)$event['claims'] ?></td>
                        <td><?= (int)$event['refunds'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">GROUP × VENUE</span>
            <h2>Relationship portfolio</h2>
        </div>
        <span class="cv-status"><?= $relationshipCount ?> relationship<?= $relationshipCount === 1 ? '' : 's' ?></span>
    </div>

    <?php if (!$relationships): ?>
        <div class="cv-card cv-empty">
            <h2>No venue relationships yet.</h2>
            <p>A relationship appears after a Coveted event is assigned to one of this business’s locations. Cancelled events do not create relationship history.</p>
            <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=locations">Manage Locations</a>
        </div>
    <?php else: ?>
        <section class="cv-list" aria-label="Venue relationships">
            <?php foreach ($relationships as $relationship): ?>
                <article class="cv-card cv-event-row">
                    <div class="cv-event-copy">
                        <span class="cv-kicker"><?= coveted_e($statusLabels[(string)$relationship['relationship_status']] ?? 'Relationship') ?></span>
                        <h2><?= coveted_e($relationship['group_name']) ?></h2>
                        <p><?= coveted_e($relationship['location_name']) ?><?php if (!empty($relationship['city']) || !empty($relationship['region'])): ?> · <?= coveted_e(trim((string)$relationship['city'] . (!empty($relationship['city']) && !empty($relationship['region']) ? ', ' : '') . (string)$relationship['region'])) ?><?php endif; ?></p>
                        <div class="cv-meta-row">
                            <span><?= (int)$relationship['completed_events'] ?> completed events</span>
                            <span><?= (int)$relationship['verified_visits'] ?> verified visits</span>
                            <span><?= (int)$relationship['repeat_attendees'] ?> repeat attendees</span>
                            <span><?= (int)$relationship['business_benefits_issued'] ?> benefits issued</span>
                            <span><?= (int)$relationship['claims'] ?> claims</span>
                            <span><?= (int)$relationship['return_claims'] ?> return-linked claims</span>
                        </div>
                        <div class="cv-action-row">
                            <a class="cv-button" href="/venue-relationships.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;group=<?= coveted_e(rawurlencode((string)$relationship['group_public_id'])) ?>&amp;location=<?= coveted_e(rawurlencode((string)$relationship['location_public_id'])) ?>">Open Relationship</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php coveted_page_end(); ?>
