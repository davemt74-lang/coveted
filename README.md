# Coveted

Coveted is an offline-first private social membership platform built around real-world gatherings, curated invitations, mystery experiences, partner venues, artist partners, campaigns, rewards, and member benefits.

## Product principle

Technology gets members where they need to be, then gets out of the way.

Coveted owns the complete member and partner experience:

- accounts and roles
- groups and memberships
- invitations and guest passes
- events, RSVP, waitlists, and attendance
- mystery reveal schedules
- venue and artist relationships
- campaigns and reward templates
- reward/media distribution, claims, and redemption
- campaign activity and attribution
- post-event reconnects and memories
- member-facing benefits
- persistent audio player and media viewer

Coveted is intentionally a modular monolith. Social, event, partner, campaign, reward, media, and attribution truth live in the same application/database while remaining separated by permanent domain modules.

## Platform roles

- Attendee
- Attendee Host
- Business / Location Host
- Artist Partner
- Admin

Roles are additive. A single Coveted user may hold more than one role.

Group roles are separate from platform roles:

- Guest
- Member
- Host
- Group Admin

## Initial navigation

Attendee-facing navigation:

- Home
- Invitations
- Events
- Benefits
- Profile

Playable media is surfaced through a persistent global audio player instead of a separate music-first navigation area.

## Application structure

Coveted uses a small, domain-oriented PHP structure. New files should represent permanent product routes or permanent application domains, not build phases or temporary iterations.

```text
app/
  bootstrap.php     Shared config, database, sessions, auth, access control, audit
  admin.php         Platform-admin mutations and safety invariants
  groups.php        Group membership, permissions, invitations, lifecycle
  events.php        Event visibility, RSVP, capacity, local-time rules
  rewards.php       Reward templates, media, issuance, claim, redemption
  campaigns.php     Campaign creation, event links, distribution triggers

admin/
  index.php         Platform administration route/view

assets/
  css/coveted.css   The single application stylesheet
  js/coveted.js     Shared browser behavior

database/
  schema.sql        Canonical pre-install schema

tests/
  foundation-smoke.php  MySQL-backed foundation/domain smoke test
```

## Development discipline

These rules are part of the Coveted engineering standard and apply to every build.

- Fix defects at the source. Do not mask broken behavior with fallbacks, compatibility shims, duplicate handlers, or alternate code paths unless the product explicitly requires backward compatibility.
- Do not create quick-patch files, temporary replacement files, version-suffixed files, backup copies, or parallel implementations such as `page-v2.php`, `events-fixed.php`, `phase3.css`, or `new-bootstrap.php`.
- When existing code is wrong, repair or replace that code directly and delete the superseded implementation.
- Extend an existing domain file when the responsibility already has a clear home. Add a new file only when it represents a durable route, domain boundary, service, or operational tool.
- Keep one canonical implementation for each responsibility. Authentication, permissions, invitations, events, campaigns, rewards, styling, and database definitions must each have a clear source of truth.
- Keep one application stylesheet: `assets/css/coveted.css`. Do not add page-, feature-, version-, or phase-specific CSS files.
- Keep shared browser behavior in `assets/js/coveted.js` unless a future feature becomes large enough to justify a permanent JavaScript module boundary.
- Do not accumulate dead code, commented-out implementations, unused schema columns, abandoned routes, or unused helper functions. Remove them during the same change that makes them obsolete.
- Never solve a permission or lifecycle problem only in the UI. Authorization and state invariants must be enforced server-side at the mutation point.
- Prefer transactions for multi-record state changes. If one part of an operation fails, the application should not leave partially applied state behind.
- Use database constraints and indexes to enforce durable invariants and support real query patterns instead of relying only on application convention.
- Keep campaign and reward state authoritative inside the native Coveted domains. Do not duplicate or shadow the same state in page controllers.
- Keep privileged operations server-side. Never trust browser-provided ownership, role, campaign, claim, or redemption authority.
- Security fixes should remove the unsafe behavior rather than add a secondary safer path while leaving the unsafe path available.
- Optimize for readability before cleverness. Source files should be formatted, named consistently, and understandable without reconstructing build history.
- Avoid premature abstraction. Extract shared logic when there is a real repeated domain responsibility, not merely to reduce line count.
- Before adding a new dependency, table, column, route, helper, or file, verify that an existing structure cannot cleanly own the responsibility.
- Every meaningful build follows: review and score → identify concrete defects → fix them at the source → run validation → rescore. Repeat until no material foundation defects remain.

### Database rule before first install

Coveted has not been installed in production yet. Until the first real deployment:

- `database/schema.sql` is the single database source of truth.
- Modify the canonical schema directly instead of creating migrations.
- Remove obsolete columns/tables rather than preserving compatibility with a database that has never been deployed.
- Store all `DATETIME` values in UTC. PHP and the database connection both operate in UTC.
- Store the IANA timezone on each location and snapshot it onto each event so local event time remains historically correct.
- Event audience is explicit: `group` events may be visible to active group members; `invitation_only` events require invitation/history or management access.
- Campaigns and reward templates are separate objects. Events may link to multiple campaigns; an Event is never itself a Campaign.
- Reward ownership may belong to the platform, a group, a business, or an artist. Authorization is enforced in the native domain service.
- Reward issuance is idempotent where a deterministic trigger exists, and redemptions have one authoritative issuance state.

After the first production install, schema changes become additive migrations and deployed data must be preserved.

### Repository hygiene

Do not commit:

- fallback implementations
- build-phase runtime files
- versioned copies of active source files
- local configuration or secrets
- generated temporary files
- unused migration files before first install
- broken code kept "just in case"

If code is replaced, the replacement becomes the canonical implementation and the replaced code is deleted.

## V1 build order

1. Foundation — authentication, profiles, roles, app shell, database foundation
2. Groups — groups, membership, invitations, hosts, guest passes, Admin controls
3. Events — events, RSVP, waitlists, attendance, mystery reveals
4. Native value engine — campaigns, reward templates, distribution, claims, redemption, media entitlement, attribution
5. Partners — businesses, locations, artists, relationship records and partner management
6. Benefits — member-facing benefits and reward lifecycle presentation
7. Media — persistent audio player, video viewer, artist media rewards and artist pages
8. Post-event — event memories, reconnects, return benefits, mystery rewards
9. Partner dashboards — venue economics, campaign performance, return visits, and artist engagement

## Architecture boundary

```text
COVETED MODULAR MONOLITH

Identity / Roles
      |
Groups / Memberships / Invitations
      |
Events / RSVP / Attendance / Mystery
      |
Partners: Venues / Businesses / Artists
      |
Campaigns -> Reward Templates -> Issuances
      |                           |
      |                           +-> Claims -> Redemption
      |                           +-> Audio / Video / Media Entitlement
      |
Campaign Activity / Attribution / Partner Analytics
      |
Benefits UI / Persistent Player / Post-event Experience
```

The modules share one database but do not bypass each other's domain invariants. Page controllers render and coordinate; they do not become alternate sources of campaign, reward, membership, or event truth.

## Repository

This repository is the standalone Coveted application and its complete native campaign/reward engine.
