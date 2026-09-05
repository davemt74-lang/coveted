# Coveted notifications

Coveted stores one canonical in-app notification and projects delivery rows for active client devices. Product domains never call Web Push directly.

## Current clients

- Web/PWA: `web_push`
- iOS: reserved for future `apns` or `fcm`
- Android: reserved for future `fcm`

## Web Push setup

Configure the `push` section in `config.php` with a VAPID subject, public key, and private key. Secrets stay server-side. The public key is exposed to authenticated web clients only for browser subscription creation.

Install production dependencies with Composer. The Web Push transport uses `minishlink/web-push`.

## Delivery worker

Run:

```bash
php scripts/dispatch-push.php
```

The worker first reconciles durable product events into canonical notifications, then claims and delivers pending Web Push rows. It is safe to run repeatedly because both notification projection and delivery creation are idempotent.

A production deployment should run the worker on a short recurring schedule appropriate for expected notification latency. The worker uses bounded retries and recovers stale delivery leases.

## Product projections

The reconciliation layer currently projects:

- pending event invitations
- waitlist promotions
- due mystery-event reveals for attending members
- reward refunds

Reward distribution already creates its notification directly because distribution is a System Admin-controlled transaction and has its own idempotent self-healing path.

## Shared-device privacy

Each browser has one locally generated client ID. Registering the same browser under another account reassigns the canonical device row and cancels queued deliveries for the previous account. Logging out deactivates the current browser device before the session is destroyed.

## Admin operations

System Admin → PWA exposes install artwork and notification history. The shared admin client also loads `/admin/push.php` for current delivery readiness, active device counts, pending/sent/failed counts, and manual dispatch of pending notifications.
