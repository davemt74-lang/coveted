<?php
declare(strict_types=1);

require_once __DIR__ . '/app/return_engine.php';

$user = coveted_require_user();
$error = '';
$notice = trim((string)($_SESSION['return_program_notice'] ?? ''));
unset($_SESSION['return_program_notice']);

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
        if ($action !== 'link_program') {
            throw new InvalidArgumentException('Unsupported return-program action.');
        }

        $summary = coveted_return_program_link_relationship_events(
            $user,
            (int)$business['id'],
            $requestedGroup,
            $requestedLocation,
            (string)($_POST['campaign'] ?? '')
        );

        $_SESSION['return_program_notice'] = (int)$summary['linked_count'] > 0
            ? (int)$summary['linked_count'] . ' relationship event' . ((int)$summary['linked_count'] === 1 ? '' : 's') . ' linked to the return program.'
            : 'That return program is already linked to every eligible relationship event.';

        coveted_redirect(
            '/return-programs.php?business=' . rawurlencode((string)$business['public_id'])
            . '&group=' . rawurlencode($requestedGroup)
            . '&location=' . rawurlencode($requestedLocation)
        );
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted return program workspace error: ' . $e->getMessage());
        $error = 'Unable to update that return program right now.';
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
        error_log('Coveted return relationship load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load return relationships right now.';
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

$programs = [];
if ($business && $selected) {
    try {
        $programs = coveted_return_programs_for_relationship(
            $user,
            (int)$business['id'],
            (string)$selected['group_public_id'],
            (string)$selected['location_public_id']
        );
    } catch (Throwable $e) {
        error_log('Coveted return program load error: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Unable to load return programs right now.';
    }
}

$readyProgramCount = count(array_filter(
    $programs,
    static fn(array $program): bool => (string)$program['status'] === 'active'
        && (string)$program['reward_status'] === 'active'
        && (int)$program['linked_event_count'] > 0
));

coveted_page_start('Return Programs');
?>
<section class="cv-page-heading">
    <span class="cv-eyebrow">RETURN PROGRAMS</span>
    <h1><?= $business ? coveted_e($business['name']) : 'Relationship return engine' ?></h1>
    <p>Turn a real post-event venue return into the next benefit without creating a second visit tracker or loyalty ledger.</p>
</section>

<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

<?php if (!$business): ?>
    <div class="cv-card cv-empty">
        <h2>No business workspace is available.</h2>
        <p>Return programs are available only to a scoped Business Admin or Coveted System Admin.</p>
        <a class="cv-button cv-button-soft" href="/profile.php">Back to Profile</a>
    </div>
    <?php coveted_page_end(); exit; ?>
<?php endif; ?>

<?php $businessRef = (string)$business['public_id']; ?>
<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">CURRENT BUSINESS</span>
        <h2><?= coveted_e($business['name']) ?></h2>
    </div>
    <div class="cv-member-actions">
        <a class="cv-button cv-button-soft" href="/venue-relationships.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">Venue Relationships</a>
        <a class="cv-button cv-button-soft" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=campaigns">Campaigns</a>
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

<article class="cv-card cv-feature-card cv-copy-card">
    <span class="cv-kicker">THE RETURN LOOP</span>
    <h2>Event → benefit → real return → next benefit.</h2>
    <p>A return is recognized only when a verified attendee claims an event-sourced business benefit at the same venue on a later local date. A guest return uses the same proof, plus guest or +1 invitation history. The claim remains the source of truth.</p>
    <div class="cv-tag-row">
        <span class="cv-pill">Same venue required</span>
        <span class="cv-pill">Later local date required</span>
        <span class="cv-pill">Idempotent reward issuance</span>
        <span class="cv-pill">No new visit ledger</span>
    </div>
</article>

<?php if ($selected): ?>
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">RELATIONSHIP PROGRAMS</span>
            <h2><?= coveted_e($selected['group_name']) ?> × <?= coveted_e($selected['location_name']) ?></h2>
        </div>
        <a class="cv-button cv-button-soft" href="/return-programs.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">All Relationships</a>
    </div>

    <section class="cv-stat-grid cv-home-stats" aria-label="Return program status">
        <div class="cv-card cv-stat"><strong><?= count($programs) ?></strong><span>Available return programs</span></div>
        <div class="cv-card cv-stat"><strong><?= $readyProgramCount ?></strong><span>Linked active programs</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$selected['return_claims'] ?></strong><span>Return-linked claims</span></div>
        <div class="cv-card cv-stat"><strong><?= (int)$selected['guest_return_claims'] ?></strong><span>Guest-return claims</span></div>
    </section>

    <?php if ((int)$selected['benefits_enabled'] !== 1): ?>
        <div class="cv-alert cv-alert-error">
            Automatic return benefits are off for this relationship. Enable Partner benefits before Coveted will trigger a return program.
            <a class="cv-text-link" href="/venue-relationships.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;group=<?= coveted_e(rawurlencode((string)$selected['group_public_id'])) ?>&amp;location=<?= coveted_e(rawurlencode((string)$selected['location_public_id'])) ?>">Manage relationship →</a>
        </div>
    <?php endif; ?>

    <div class="cv-section-head">
        <div><span class="cv-eyebrow">PROGRAMS</span><h2>What should happen when someone comes back</h2></div>
        <a class="cv-text-link" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=campaigns">Create or edit campaigns →</a>
    </div>

    <section class="cv-list" aria-label="Relationship return programs">
        <?php if (!$programs): ?>
            <div class="cv-card cv-empty">
                <h2>No return programs yet.</h2>
                <p>Create a business campaign with a Return Visit or Guest Return trigger, then come back here to connect it to this venue relationship.</p>
                <a class="cv-button cv-button-primary" href="/business.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;tab=campaigns">Open Campaigns</a>
            </div>
        <?php endif; ?>

        <?php foreach ($programs as $program): ?>
            <?php
            $linked = (int)$program['linked_event_count'];
            $eligible = (int)$program['eligible_event_count'];
            $active = (string)$program['status'] === 'active' && (string)$program['reward_status'] === 'active';
            $ready = $active && $linked > 0 && (int)$selected['benefits_enabled'] === 1;
            ?>
            <article class="cv-card cv-event-row">
                <div class="cv-event-copy">
                    <div class="cv-tag-row">
                        <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$program['trigger_key']))) ?></span>
                        <span class="cv-status"><?= $ready ? 'Ready' : coveted_e(ucfirst((string)$program['status'])) ?></span>
                    </div>
                    <h3><?= coveted_e($program['title']) ?></h3>
                    <p><?= coveted_e($program['reward_title']) ?> · <?= coveted_e($program['campaign_location_name'] ?: 'Any business location') ?></p>
                    <div class="cv-meta-row">
                        <span><?= $linked ?> of <?= $eligible ?> relationship events linked</span>
                        <span><?= coveted_e(ucwords(str_replace('_', ' ', (string)$program['reward_type']))) ?></span>
                        <?php if ($program['per_user_limit'] !== null): ?><span>Member limit <?= (int)$program['per_user_limit'] ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="cv-member-actions">
                    <?php if ($eligible > $linked): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                            <input type="hidden" name="action" value="link_program">
                            <input type="hidden" name="business" value="<?= coveted_e($businessRef) ?>">
                            <input type="hidden" name="group" value="<?= coveted_e($selected['group_public_id']) ?>">
                            <input type="hidden" name="location" value="<?= coveted_e($selected['location_public_id']) ?>">
                            <input type="hidden" name="campaign" value="<?= coveted_e($program['public_id']) ?>">
                            <button class="cv-button cv-button-primary" type="submit">Link Relationship Events</button>
                        </form>
                    <?php else: ?>
                        <span class="cv-status">Events linked</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <div class="cv-section-head">
        <div><span class="cv-eyebrow">CHOOSE RELATIONSHIP</span><h2>Where should the return loop run?</h2></div>
        <span class="cv-status"><?= count($relationships) ?> relationship<?= count($relationships) === 1 ? '' : 's' ?></span>
    </div>

    <?php if (!$relationships): ?>
        <div class="cv-card cv-empty">
            <h2>No venue relationships yet.</h2>
            <p>Return programs begin only after Coveted has a real group-to-venue event relationship.</p>
            <a class="cv-button cv-button-soft" href="/venue-relationships.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>">Open Venue Relationships</a>
        </div>
    <?php else: ?>
        <section class="cv-list" aria-label="Return program relationships">
            <?php foreach ($relationships as $relationship): ?>
                <article class="cv-card cv-event-row">
                    <div class="cv-event-copy">
                        <div class="cv-tag-row">
                            <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$relationship['relationship_status']))) ?></span>
                            <span class="cv-status"><?= (int)$relationship['benefits_enabled'] === 1 ? 'Benefits on' : 'Benefits off' ?></span>
                        </div>
                        <h3><?= coveted_e($relationship['group_name']) ?> × <?= coveted_e($relationship['location_name']) ?></h3>
                        <div class="cv-meta-row">
                            <span><?= (int)$relationship['completed_events'] ?> completed events</span>
                            <span><?= (int)$relationship['verified_visits'] ?> verified visits</span>
                            <span><?= (int)$relationship['return_claims'] ?> return-linked claims</span>
                        </div>
                    </div>
                    <a class="cv-button cv-button-soft" href="/return-programs.php?business=<?= coveted_e(rawurlencode($businessRef)) ?>&amp;group=<?= coveted_e(rawurlencode((string)$relationship['group_public_id'])) ?>&amp;location=<?= coveted_e(rawurlencode((string)$relationship['location_public_id'])) ?>">Configure Returns</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php coveted_page_end(); ?>