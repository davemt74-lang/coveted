<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/operations.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$snapshot = coveted_operations_snapshot($admin);
$summary = $snapshot['summary'];
$lifecycleBacklog = $snapshot['lifecycle_backlog'];

$formatTime = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    return coveted_utc_datetime($value)->setTimezone(coveted_timezone())->format('M j, Y · g:i A');
};

coveted_page_start('Launch Operations', '', true);
coveted_admin_ui_start($admin, 'operations', 'Operations');
?>
        <div class="cv-page-heading">
            <span class="cv-eyebrow">LAUNCH OPERATIONS</span>
            <h1>What needs attention now.</h1>
            <p>Read-only operational health derived from Coveted's canonical event, notification, claim and audit records.</p>
        </div>

        <section class="cv-stat-grid" aria-label="Operational summary">
            <div class="cv-card cv-stat">
                <strong><?= (int)$summary['attention_count'] ?></strong>
                <span>Attention items</span>
            </div>
            <a class="cv-card cv-stat" href="/admin/?view=requests">
                <strong><?= (int)$summary['pending_role_requests'] ?></strong>
                <span>Role requests</span>
            </a>
            <a class="cv-card cv-stat" href="/admin/?view=users">
                <strong><?= (int)$summary['suspended_accounts'] ?></strong>
                <span>Suspended accounts</span>
            </a>
            <div class="cv-card cv-stat">
                <strong><?= (int)$summary['overdue_events'] ?></strong>
                <span>Overdue events</span>
            </div>
            <div class="cv-card cv-stat">
                <strong><?= (int)$summary['upcoming_without_location'] ?></strong>
                <span>Location attention</span>
            </div>
            <div class="cv-card cv-stat">
                <strong><?= (int)$summary['lifecycle_backlog'] ?></strong>
                <span>Lifecycle backlog</span>
            </div>
            <a class="cv-card cv-stat" href="/admin/?view=pwa">
                <strong><?= (int)$summary['permanent_failures_24h'] ?></strong>
                <span>Permanent push failures · 24h</span>
            </a>
            <a class="cv-card cv-stat" href="/admin/?view=pwa">
                <strong><?= (int)$summary['retryable_failures_24h'] ?></strong>
                <span>Retryable push failures · 24h</span>
            </a>
            <a class="cv-card cv-stat" href="/admin/?view=pwa">
                <strong><?= (int)$summary['stuck_deliveries'] ?></strong>
                <span>Stuck deliveries</span>
            </a>
            <div class="cv-card cv-stat">
                <strong><?= (int)$summary['claims_7d'] ?></strong>
                <span>Claims · 7d</span>
            </div>
            <div class="cv-card cv-stat">
                <strong><?= (int)$summary['refunds_7d'] ?></strong>
                <span>Refunds · 7d</span>
            </div>
        </section>

        <?php if ((int)$summary['attention_count'] === 0): ?>
            <article class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
                <span class="cv-kicker">OPERATIONS CLEAR</span>
                <h2>No launch-health queue requires attention.</h2>
                <p>This is an operational snapshot, not a guarantee that every product or partner workflow is complete.</p>
            </article>
        <?php endif; ?>

        <?php if ((int)$summary['lifecycle_backlog'] > 0): ?>
            <article class="cv-card cv-feature-card cv-copy-card cv-admin-section-gap">
                <span class="cv-kicker">LIFECYCLE WORKER</span>
                <h2><?= (int)$summary['lifecycle_backlog'] ?> stale lifecycle record<?= (int)$summary['lifecycle_backlog'] === 1 ? '' : 's' ?> need reconciliation.</h2>
                <p>The scheduled lifecycle worker should keep invitation and Guest Pass state aligned with time-based availability. A non-zero backlog can indicate that the worker is missing, stalled or has not run recently.</p>
                <div class="cv-tag-row">
                    <span class="cv-pill"><?= (int)$lifecycleBacklog['group_invitations'] ?> group invitation<?= (int)$lifecycleBacklog['group_invitations'] === 1 ? '' : 's' ?></span>
                    <span class="cv-pill"><?= (int)$lifecycleBacklog['event_invitations'] ?> event invitation<?= (int)$lifecycleBacklog['event_invitations'] === 1 ? '' : 's' ?></span>
                    <span class="cv-pill"><?= (int)$lifecycleBacklog['guest_passes'] ?> Guest Pass<?= (int)$lifecycleBacklog['guest_passes'] === 1 ? '' : 'es' ?></span>
                </div>
                <p class="cv-form-help">Server operation: <code>php scripts/reconcile-lifecycle.php</code></p>
            </article>
        <?php endif; ?>

        <div class="cv-section-head cv-admin-section-gap">
            <div>
                <span class="cv-eyebrow">EVENT LIFECYCLE</span>
                <h2>Overdue live events</h2>
            </div>
            <span class="cv-pill"><?= count($snapshot['overdue_events']) ?> shown</span>
        </div>
        <section class="cv-stack">
            <?php if (!$snapshot['overdue_events']): ?>
                <div class="cv-card cv-empty"><h3>Event lifecycle is current.</h3><p>No published or closed event is more than six hours past its end/start time without completion or cancellation.</p></div>
            <?php endif; ?>
            <?php foreach ($snapshot['overdue_events'] as $event): ?>
                <article class="cv-card cv-admin-row">
                    <div>
                        <div class="cv-tag-row">
                            <span class="cv-kicker"><?= coveted_e(strtoupper((string)$event['status'])) ?></span>
                            <span class="cv-pill"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$event['event_type']))) ?></span>
                        </div>
                        <h3><?= coveted_e($event['title']) ?></h3>
                        <p><?= coveted_e($event['group_name']) ?> · <?= coveted_e($formatTime((string)($event['ends_at'] ?: $event['starts_at']))) ?></p>
                        <p>Created by <?= coveted_e($event['creator_name']) ?></p>
                    </div>
                    <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e($event['public_id']) ?>">Open Event</a>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="cv-section-head cv-admin-section-gap">
            <div>
                <span class="cv-eyebrow">NEXT 72 HOURS</span>
                <h2>Published events without a location</h2>
            </div>
            <span class="cv-pill"><?= count($snapshot['location_attention']) ?> shown</span>
        </div>
        <section class="cv-stack">
            <?php if (!$snapshot['location_attention']): ?>
                <div class="cv-card cv-empty"><h3>No location gaps.</h3><p>Every published event inside the next 72 hours has either a canonical venue or a private location label.</p></div>
            <?php endif; ?>
            <?php foreach ($snapshot['location_attention'] as $event): ?>
                <article class="cv-card cv-admin-row">
                    <div>
                        <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$event['location_visibility']))) ?></span>
                        <h3><?= coveted_e($event['title']) ?></h3>
                        <p><?= coveted_e($event['group_name']) ?> · <?= coveted_e($formatTime((string)$event['starts_at'])) ?></p>
                    </div>
                    <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e($event['public_id']) ?>">Open Event</a>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="cv-section-head cv-admin-section-gap">
            <div>
                <span class="cv-eyebrow">DELIVERY HEALTH</span>
                <h2>Notification failures</h2>
            </div>
            <div class="cv-action-row">
                <span class="cv-pill"><?= count($snapshot['delivery_failures']) ?> · last 7 days</span>
                <a class="cv-text-link" href="/admin/?view=pwa">Open PWA operations →</a>
            </div>
        </div>
        <section class="cv-stack">
            <?php if (!$snapshot['delivery_failures']): ?>
                <div class="cv-card cv-empty"><h3>No recent delivery failures.</h3><p>Notification delivery has no failed or permanent-failure record in the last seven days.</p></div>
            <?php endif; ?>
            <?php foreach ($snapshot['delivery_failures'] as $delivery): ?>
                <article class="cv-card cv-admin-row">
                    <div>
                        <div class="cv-tag-row">
                            <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$delivery['status']))) ?></span>
                            <span class="cv-pill"><?= coveted_e(strtoupper((string)$delivery['transport'])) ?></span>
                        </div>
                        <h3><?= coveted_e($delivery['title']) ?></h3>
                        <p>
                            <?= coveted_e($delivery['recipient_name']) ?>
                            · <?= (int)$delivery['attempts'] ?> attempt<?= (int)$delivery['attempts'] === 1 ? '' : 's' ?>
                            <?php if ($delivery['response_code'] !== null): ?> · HTTP <?= (int)$delivery['response_code'] ?><?php endif; ?>
                            · <?= coveted_e($formatTime((string)$delivery['updated_at'])) ?>
                        </p>
                        <?php if ((string)$delivery['status'] === 'failed' && $delivery['next_attempt_at']): ?>
                            <p>Next canonical retry <?= coveted_e($formatTime((string)$delivery['next_attempt_at'])) ?>.</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($snapshot['stuck_deliveries']): ?>
            <div class="cv-section-head cv-admin-section-gap">
                <div><span class="cv-eyebrow">QUEUE HEALTH</span><h2>Stuck deliveries</h2></div>
                <span class="cv-pill"><?= count($snapshot['stuck_deliveries']) ?> shown</span>
            </div>
            <section class="cv-stack">
                <?php foreach ($snapshot['stuck_deliveries'] as $delivery): ?>
                    <article class="cv-card cv-admin-row">
                        <div>
                            <span class="cv-kicker"><?= coveted_e(strtoupper((string)$delivery['status'])) ?></span>
                            <h3><?= coveted_e($delivery['title']) ?></h3>
                            <p><?= coveted_e(strtoupper((string)$delivery['transport'])) ?> · <?= (int)$delivery['attempts'] ?> attempt<?= (int)$delivery['attempts'] === 1 ? '' : 's' ?> · Last touched <?= coveted_e($formatTime((string)$delivery['updated_at'])) ?></p>
                            <?php if ($delivery['next_attempt_at']): ?><p>Retry became eligible <?= coveted_e($formatTime((string)$delivery['next_attempt_at'])) ?>.</p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <div class="cv-section-head cv-admin-section-gap">
            <div>
                <span class="cv-eyebrow">COMMERCE ACTIVITY</span>
                <h2>Recent claims & refunds</h2>
            </div>
            <a class="cv-text-link" href="/business.php">Business Workspace →</a>
        </div>
        <section class="cv-stack">
            <?php if (!$snapshot['claim_activity']): ?>
                <div class="cv-card cv-empty"><h3>No claim activity in the last seven days.</h3></div>
            <?php endif; ?>
            <?php foreach ($snapshot['claim_activity'] as $claim): ?>
                <article class="cv-card cv-admin-row">
                    <div>
                        <div class="cv-tag-row">
                            <span class="cv-kicker"><?= coveted_e(strtoupper((string)$claim['status'])) ?></span>
                            <span class="cv-pill"><?= coveted_e(strtoupper((string)$claim['claim_code_type'])) ?></span>
                        </div>
                        <h3><?= coveted_e($claim['reward_title']) ?></h3>
                        <p><?= coveted_e($claim['business_name']) ?> · <?= coveted_e($claim['location_name']) ?> · <?= coveted_e($claim['claim_code_label']) ?></p>
                        <p>Claimed <?= coveted_e($formatTime((string)$claim['claimed_at'])) ?><?php if ($claim['refunded_at']): ?> · Refunded <?= coveted_e($formatTime((string)$claim['refunded_at'])) ?><?php endif; ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="cv-section-head cv-admin-section-gap">
            <div>
                <span class="cv-eyebrow">AUDIT TRAIL</span>
                <h2>Recent canonical mutations</h2>
            </div>
            <span class="cv-pill"><?= count($snapshot['audit_trail']) ?> shown</span>
        </div>
        <section class="cv-stack">
            <?php if (!$snapshot['audit_trail']): ?>
                <div class="cv-card cv-empty"><h3>No audit events yet.</h3></div>
            <?php endif; ?>
            <?php foreach ($snapshot['audit_trail'] as $audit): ?>
                <article class="cv-card cv-admin-row">
                    <div>
                        <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace(['.', '_'], ' ', (string)$audit['event_type']))) ?></span>
                        <h3><?= coveted_e($audit['entity_type'] ?: 'Platform') ?><?= $audit['entity_id'] ? ' · ' . coveted_e($audit['entity_id']) : '' ?></h3>
                        <p><?= coveted_e($audit['actor_name'] ?: 'System') ?> · <?= coveted_e($formatTime((string)$audit['created_at'])) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <p class="cv-muted cv-admin-section-gap">Snapshot generated <?= coveted_e($formatTime((string)$snapshot['generated_at'])) ?>. Operational health is read-only; corrective actions remain in each canonical workspace.</p>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
