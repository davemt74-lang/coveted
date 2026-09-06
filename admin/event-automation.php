<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/event_lifecycle_automation.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$exceptions = coveted_event_lifecycle_automation_exceptions(50);

$formatTime = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};

coveted_page_start('Event Automation', '', true);
coveted_admin_ui_start($admin, 'event-automation', 'Event Automation');
?>
<div class="cv-page-heading">
    <span class="cv-eyebrow">EVENT LIFECYCLE AUTOMATION</span>
    <h1>Automate the routine. Surface the exceptions.</h1>
    <p>Read-only exception monitoring for invitations, reminders, mystery reveals, attendance rewards and the post-event handoff. Event creation, configuration and completion remain System Admin decisions.</p>
</div>

<section class="cv-stat-grid" aria-label="Event automation exceptions">
    <div class="cv-card cv-stat">
        <strong><?= count($exceptions['pending_rsvp']) ?></strong>
        <span>Events with RSVP risk</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= count($exceptions['unrevealed_mystery']) ?></strong>
        <span>Mystery delivery gaps</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= count($exceptions['reward_gaps']) ?></strong>
        <span>Reward issuance gaps</span>
    </div>
    <div class="cv-card cv-stat">
        <strong><?= count($exceptions['recent_failure_runs']) ?></strong>
        <span>Worker runs with failures · 24h</span>
    </div>
</section>

<article class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
    <span class="cv-kicker">SCHEDULER</span>
    <h2>One bounded CLI lifecycle worker.</h2>
    <p><code>php scripts/reconcile-lifecycle.php</code> now reconciles invitation/Guest Pass state and then runs event lifecycle automation. It is CLI-only, protected by an application-level database lock, and safe to run repeatedly because notifications use canonical dedupe keys and rewards use canonical issuance idempotency keys.</p>
    <div class="cv-tag-row">
        <span class="cv-pill">Recommended · every 5 minutes</span>
        <span class="cv-pill">No public worker endpoint</span>
        <span class="cv-pill">No new SQL</span>
    </div>
    <p class="cv-form-help">Web Push delivery remains on the existing transport worker: <code>php scripts/dispatch-push.php</code>. In-app notifications exist as soon as the lifecycle worker creates them.</p>
    <p class="cv-form-help">Last recorded automation activity: <?= coveted_e($formatTime($exceptions['last_run_at'])) ?><?php if ((int)$exceptions['last_run_failures'] > 0): ?> · <?= (int)$exceptions['last_run_failures'] ?> failure<?= (int)$exceptions['last_run_failures'] === 1 ? '' : 's' ?><?php endif; ?>. No recorded activity can also mean nothing was due.</p>
</article>

<?php if ((int)$exceptions['total'] === 0): ?>
    <article class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
        <span class="cv-kicker">AUTOMATION CLEAR</span>
        <h2>No event lifecycle exception requires attention.</h2>
        <p>The worker may still have routine notifications or rewards to process on its next scheduled run.</p>
    </article>
<?php endif; ?>

<div class="cv-section-head cv-admin-section-gap">
    <div>
        <span class="cv-eyebrow">RSVP HEALTH</span>
        <h2>Events inside 24 hours with pending responses</h2>
    </div>
    <span class="cv-pill"><?= count($exceptions['pending_rsvp']) ?> shown</span>
</div>
<section class="cv-stack">
    <?php if (!$exceptions['pending_rsvp']): ?>
        <div class="cv-card cv-empty"><h3>No immediate RSVP exception.</h3><p>There is no published event inside 24 hours with an outstanding invitation response.</p></div>
    <?php endif; ?>
    <?php foreach ($exceptions['pending_rsvp'] as $event): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <span class="cv-kicker">RSVP ATTENTION</span>
                <h3><?= coveted_e((string)$event['title']) ?></h3>
                <p><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e($formatTime((string)$event['starts_at'])) ?></p>
                <p><?= (int)$event['pending_count'] ?> invitation<?= (int)$event['pending_count'] === 1 ? '' : 's' ?> still need a response. The worker sends one bounded 24-hour RSVP reminder per person.</p>
            </div>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Open Event</a>
        </article>
    <?php endforeach; ?>
</section>

<div class="cv-section-head cv-admin-section-gap">
    <div>
        <span class="cv-eyebrow">MYSTERY DELIVERY</span>
        <h2>Due reveals not yet delivered to every attendee</h2>
    </div>
    <span class="cv-pill"><?= count($exceptions['unrevealed_mystery']) ?> shown</span>
</div>
<section class="cv-stack">
    <?php if (!$exceptions['unrevealed_mystery']): ?>
        <div class="cv-card cv-empty"><h3>Mystery delivery is current.</h3><p>No due reveal is waiting on an attendee notification.</p></div>
    <?php endif; ?>
    <?php foreach ($exceptions['unrevealed_mystery'] as $event): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <span class="cv-kicker">REVEAL GAP</span>
                <h3><?= coveted_e((string)$event['title']) ?></h3>
                <p><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e($formatTime((string)$event['starts_at'])) ?></p>
                <p><?= (int)$event['missing_notifications'] ?> attendee/reveal notification<?= (int)$event['missing_notifications'] === 1 ? '' : 's' ?> remain. The scheduled worker should clear these automatically.</p>
            </div>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Review Event</a>
        </article>
    <?php endforeach; ?>
</section>

<div class="cv-section-head cv-admin-section-gap">
    <div>
        <span class="cv-eyebrow">REWARD DELIVERY</span>
        <h2>Verified attendance with an eligible reward not yet issued</h2>
    </div>
    <span class="cv-pill"><?= count($exceptions['reward_gaps']) ?> shown</span>
</div>
<section class="cv-stack">
    <?php if (!$exceptions['reward_gaps']): ?>
        <div class="cv-card cv-empty"><h3>Automated reward delivery is current.</h3><p>No active attendance/completion campaign currently has an eligible missing issuance.</p></div>
    <?php endif; ?>
    <?php foreach ($exceptions['reward_gaps'] as $event): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <span class="cv-kicker">REWARD GAP</span>
                <h3><?= coveted_e((string)$event['title']) ?></h3>
                <p><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e(strtoupper((string)$event['status'])) ?></p>
                <p><?= (int)$event['missing_issuances'] ?> eligible issuance<?= (int)$event['missing_issuances'] === 1 ? '' : 's' ?> remain after campaign quantity/per-member limits are applied.</p>
            </div>
            <a class="cv-button cv-button-soft" href="/admin/event.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Review Event</a>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($exceptions['recent_failure_runs']): ?>
    <div class="cv-section-head cv-admin-section-gap">
        <div>
            <span class="cv-eyebrow">WORKER HEALTH</span>
            <h2>Recent bounded item failures</h2>
        </div>
        <span class="cv-pill"><?= count($exceptions['recent_failure_runs']) ?> runs</span>
    </div>
    <section class="cv-stack">
        <?php foreach ($exceptions['recent_failure_runs'] as $run): ?>
            <article class="cv-card cv-admin-row">
                <div>
                    <span class="cv-kicker">AUTOMATION FAILURE</span>
                    <h3><?= (int)$run['failures'] ?> item<?= (int)$run['failures'] === 1 ? '' : 's' ?> could not be automated</h3>
                    <p><?= coveted_e($formatTime((string)$run['created_at'])) ?> · Review the server error log and the canonical event/reward configuration.</p>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<p class="cv-muted cv-admin-section-gap">Routine automation never creates events, changes event configuration, completes events, approves hosts or bypasses campaign limits. Those authorities remain in their canonical Admin workspaces.</p>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
