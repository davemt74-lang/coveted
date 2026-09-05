# Coveted Invitation Eligibility / Next Experience

## Purpose

Coveted helps an approved host plan the next gathering without turning members into a ranked social graph. The system uses explainable real-world participation history to organize invitation candidates, while private post-event choices remain private.

## Host-facing Next Invites

`/next-invites.php` is available to approved Attendee Hosts and System Admins for future events they can manage.

The candidate pool is limited to active members of the event's group. Assigned event hosts are excluded. Direct guests and +1 invitations remain available through the existing Host Workspace flow rather than this member-planning surface.

Candidate bands are intentionally non-numeric:

- **Recommended** — observable history strongly fits the target gathering.
- **Eligible** — the member has some relevant group/invitation history.
- **New history** — the member is active in the group but has little or no verified event history yet.

Explainable candidate reasons may use only host-visible facts:

- verified group attendance
- repeat verified attendance
- verified attendance at the same event type
- verified mystery-event attendance
- verified attendance at the same venue
- prior accepted invitations

Host-visible cautions may include canonical no-show history or a pattern of declined invitations. These are context, not penalties or scores.

## Private signals

Individual `event_feedback` responses and Mutual Reconnect choices are not selected into host candidate rows, are not used as candidate-level reasons, and do not change host-visible recommendation bands.

Authorized hosts may see only group-level aggregates such as:

- total Yes / Maybe / No feedback counts
- total mutual reconnect pairs
- repeat-attendee totals
- mystery-event attendance totals

Those aggregates are revalidated against current verified attendance and contain no member identity.

## Member-facing Next Experience

The member's Invitations page may show that member a private Next Experience context based on their own:

- verified attendance
- mystery-event history
- most recent private "Would you do this again?" response
- mutual reconnect history

A private `No` response records preference and suppresses the member's own mystery-ready state. It is not exposed to the host candidate surface.

## Invitation authority

Next Invites is a planning/read model plus a thin action controller. It does not own an invitation state machine.

Before sending, Coveted revalidates:

- the target event is future and published
- the actor can manage the event
- the candidate remains an active group member
- the candidate is not already assigned to the event host team
- an active invitation does not already exist

The actual send delegates to the canonical `coveted_event_invite_user()` service, preserving existing audit, invitation, RSVP and notification behavior.

## Explicit non-goals

This feature does not add:

- social scores
- points or leaderboards
- public popularity metrics
- automated invitations without host action
- member ranking based on private feedback
- member ranking based on reconnect behavior
- a new attendee navigation item
- a new invitation or notification state machine
