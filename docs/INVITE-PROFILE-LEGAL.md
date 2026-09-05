# Invite profile + legal flow

The public Coveted intake remains invite-led. The request form now captures structured event interests, structured "anything else" preferences, optional social/website links, and optional gender information.

## Data flow

- `invite_requests` remains the canonical CRM lead record.
- `invite_request_profiles` stores optional structured intake metadata linked one-to-one to the lead.
- `user_profile_intake` receives the structured metadata when an Admin converts a CRM lead into a member account.
- City and event-interest data continue to populate the canonical member profile through the existing conversion path.
- Social links and gender are not publicly exposed from the invite request.

## Legal surfaces

- `/privacy.php`
- `/terms.php`

The canonical public JavaScript adds Privacy and Terms links to the landing footer and a compact legal footer to other non-Admin pages.

## Deployment

The runtime schema guard creates the new metadata tables when needed. Existing installations should also apply `database/migrations/20260905_invite_profile_legal.sql` as part of the normal additive migration sequence.
