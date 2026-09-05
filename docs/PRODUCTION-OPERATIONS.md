# Coveted Production Operations

Coveted V1 has two scheduled CLI workers. Both are safe to run repeatedly and should execute from the application root with the production `config.php` available.

## 1. Lifecycle reconciliation

Recommended cadence: once per minute.

```sh
php scripts/reconcile-lifecycle.php
```

The worker is the canonical time-driven reconciliation path for:

- expiring pending group invitations after `expires_at`
- expiring pending event invitations once the event is no longer actionable
- releasing Guest Pass reservations when their invitation expires or becomes inactive
- expiring available/reserved Guest Passes after their own expiration

It does not complete events, change attendance, alter accepted/declined invitation history, promote Guests to Members, or make host decisions.

Optional arguments:

```sh
php scripts/reconcile-lifecycle.php 250 10
```

The first argument is the per-domain batch size (`1`–`1000`). The second is the maximum number of batches (`1`–`100`). Defaults are `250` and `10`.

Exit codes:

- `0` — reconciliation completed and the bounded queue was drained
- `1` — worker failure
- `2` — the configured batch ceiling was reached and more reconciliation work may remain

A non-zero **Lifecycle backlog** in System Admin → Operations means stale time-driven state currently exists. If it persists across worker runs, treat that as an operational fault.

## 2. Notification projection and Web Push

Recommended cadence: once per minute when Push is enabled.

```sh
php scripts/dispatch-push.php
```

This worker projects canonical notification events and dispatches eligible Web Push deliveries. Retry timing and stale `sending` leases are owned by the notification/push domain; Launch Operations reports permanent failures and genuinely stuck work without exposing endpoints, push keys, or provider error payloads.

## Example cron

Replace `/var/www/coveted` with the deployed application path and route output to the server's normal job logger.

```cron
* * * * * cd /var/www/coveted && php scripts/reconcile-lifecycle.php
* * * * * cd /var/www/coveted && php scripts/dispatch-push.php
```

Do not place secrets, database passwords, VAPID private keys, claim-code lookup keys, or tokens in the cron command. They belong only in the uncommitted production configuration / secret-management layer.

## Operational checks

System Admin → **Operations** is the read-only launch-health surface. A healthy deployment should normally show:

- zero Lifecycle backlog
- zero stuck notification deliveries
- no unexplained permanent Push failures
- no overdue published/closed events awaiting host completion/cancellation
- no published event inside the next 72 hours without a canonical or private location

Suspended-account totals and retryable Push failures are diagnostic inventory, not automatically launch-health failures.

## Source-of-truth rule

The workers reconcile existing canonical state. They do not introduce parallel ledgers or compatibility state. Before Coveted's first production install, `database/schema.sql` remains the sole schema source of truth; no additional lifecycle tables are required.
