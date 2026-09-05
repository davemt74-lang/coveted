# Coveted V1 Product Specification

## Product statement

Coveted is a private social membership application designed to create real-world relationships through curated gatherings. The application is intentionally quiet during the gathering itself: members use technology to understand where, when, and whether they are attending, then put phones away.

Post-event value comes from belonging: member access, gifts, services, venue benefits, artist media, mystery opportunities, reconnects, and reasons to return.

## Product principles

1. Real life is the primary interface.
2. No social feed, follower count, like system, or screen-time loop.
3. Membership should create access and value without public status competition.
4. Venues and artists are partners, not advertising inventory.
5. Coveted owns social, event, campaign, reward, media-entitlement, claim, and attribution truth.
6. Campaigns and rewards are internal modular domains, not separate first-layer products.

## Platform roles

Roles are additive and permission-based.

### Attendee

- maintain Coveted profile
- receive invitations
- RSVP / waitlist
- view event details and mystery reveals
- attend/check in
- view post-event memories
- receive and use member benefits
- use Inbox and Claim Box
- play entitled audio/media
- request mutual reconnects

### Attendee Host

Includes Attendee capabilities plus:

- create and manage approved gatherings
- invite group members and guests
- manage capacity/waitlist
- configure mystery reveal schedule
- select/request locations
- associate venue and artist partners
- verify attendance
- trigger post-event lifecycle
- associate approved Coveted campaigns with events

### Business Admin

Business Admin is the only business-level account role. A Coveted System Admin has the same authority across all businesses.

Business Admin capabilities:

- create/manage an approved business and locations
- manage Business Admin access
- create and rotate business claim codes
- create/manage business rewards
- create/manage business campaigns
- distribute manual campaign rewards to member Inboxes
- view claim history
- issue refunds
- review location/code/campaign attribution
- see group/venue relationship history as those features mature

There is no Business Staff account type. Employees or other people physically present at the business do not need Coveted business accounts to verify a member benefit. They enter a business-managed claim code on the member's claim screen.

### Artist Partner

- maintain artist profile
- associate with Coveted gatherings and sessions
- provide music/video/media experiences
- create approved artist reward templates and campaigns
- distribute exclusive media through event/member campaigns
- view artist/group and artist/event relationship history

An artist may also be an Attendee or Host.

### System Admin

- platform account and role administration
- group/host/business/artist approvals
- safety/moderation controls
- authority over every Coveted business
- platform-owned campaigns/rewards
- campaign/reward/claim audit review
- relationship overrides when necessary

## Group roles

Group roles are independent of platform roles:

- Guest
- Member
- Host
- Group Admin

## Attendee navigation

Primary navigation:

- Home
- Invitations
- Events
- Benefits
- Profile

There is no default social feed.

## Home

Home answers only what matters now:

- next accepted gathering
- unresolved invitation
- newly available member benefit
- newly unlocked artist/media reward
- relevant mystery reveal

## Event types

- Regular Gathering
- Mystery Gathering
- Private Table
- Member +1
- Coveted Session

## Event lifecycle

### Invite

A member receives a direct invitation tied to a group/event. Invitations may originate from group hosts, member invitation privileges, +1 rules, or standby replacement.

### RSVP

Responses:

- attending
- declined
- waitlist

### Mystery reveal

Mystery events may reveal information in stages, for example:

- immediately: date, time, general dress/expectation
- 24 hours: area/neighborhood
- 3 hours: experience type or instructions
- 30 minutes: exact location
- optional: artist reveal

`reveal_at` controls access. `notified_at` records delivery of the reveal notification and is not a second visibility state machine.

### Arrival

Attendance is verified by host check-in or an approved attendance mechanism.

The desired member experience after arrival is intentionally minimal:

> You're here. Enjoy the evening. We'll see you tomorrow.

### Offline period

No required chat, posting, photography, content creation, or in-app engagement.

### Post-event

The next-day experience can contain:

- event memory
- venue thank-you / return benefit
- artist audio/video/media benefit
- mutual reconnect request
- "would you do this again?"
- future/mystery invitation eligibility

## Invitation eligibility / next experience

Coveted may help an approved host plan a future gathering with explainable invitation bands rather than a numeric member score.

Host-facing recommendation context may use only observable participation and invitation history, including:

- verified attendance
- repeat verified attendance
- prior attendance at the same event type
- prior mystery-event attendance
- prior verified attendance at the same venue
- accepted/declined invitation history
- no-show history as contextual caution

Individual post-event feedback and Mutual Reconnect choices remain private. They must not appear in host candidate rows, host-visible reasons, or member rankings. Hosts may see only identity-free aggregate feedback and reconnect results when authorized.

A member may see their own private Next Experience context using their own attendance, latest post-event feedback, mystery history, and mutual reconnect history. A private "No" response is recorded as a private preference and suppresses that member's own mystery-ready state without exposing the answer to a host.

Recommendation bands are non-numeric:

- Recommended
- Eligible
- New history

The final invitation decision remains host-controlled. Next Invites does not auto-send invitations. Sending revalidates the target event, host authority, active group membership, existing host assignment, and prior target-event response, then delegates to the canonical event invitation service.

A member who already declined the target event is not offered for re-invitation through Next Invites. Direct guest/+1 invitation remains in the existing Host Workspace flow.

Next Invites is a host workspace, not a new attendee primary-navigation destination.

## Venue relationship model

Coveted stores the relationship between a group and a location.

Relationship states:

1. New
2. Event Venue
3. Partner
4. Preferred Partner
5. Home Venue

Coveted can measure the relationship chain in one system: event attendance, campaign issuance, claims, refunds, return visits, additional guests, and later attributable partner value.

The value proposition to a venue is not a single event booking. A group may bring 100+ qualified people into an establishment; venue benefits can incentivize those members to return and create measurable long-term value.

## Artist relationship model

Artist Partners can participate through:

- Coveted Sessions
- dinner performances
- rooftop sessions
- living-room sessions
- studio visits
- listening parties
- mystery artist appearances
- exclusive post-event media

Artist rewards can include audio, video, media packs, access, experiences, or other benefits while preserving the event/artist provenance that caused the unlock.

## Native campaigns and rewards

Coveted uses an internal modular value engine.

### Reward Template

A reward template defines **what** a member can receive. Ownership can belong to:

- Coveted/platform
- Group
- Business
- Artist

Reward types:

- credit
- free item
- discount
- perk
- access
- service
- audio
- video
- media pack
- experience
- custom

A reward chooses one of two claim modes:

- `none` — entitlement/media/access does not require a physical business claim
- `location_code` — physical business benefit requires an eligible business claim code

Templates may contain value text/amount, validity dates, cover artwork, ordered media items, and additional rules.

### Campaign

A campaign defines **why/when** a reward is distributed.

Campaign triggers include:

- attendance
- event completion
- return visit
- guest return
- random reward
- mystery unlock
- membership
- birthday
- manual distribution

A campaign has its own active window, total distribution limit, per-member limit, reward template, and optional location restriction.

### Event campaign links

Event != Campaign.

An event may link to many campaigns. For example, one dinner can trigger:

- venue return credit
- artist audio pack
- random member surprise
- mystery unlock
- later guest-return benefit

Event-triggered distribution uses deterministic idempotency so repeating the same trigger cannot issue duplicate rewards.

## Member benefit lifecycle

### Inbox

Inbox contains active reward issuances waiting for the member to use. Issuance states used by the member benefit engine are:

- issued
- viewed
- claimed
- expired
- cancelled

A physical business benefit remains in Inbox while issued/viewed.

### Business claim codes

Business Admins may create multiple 5–10 character alphanumeric codes. Codes are case-normalized and stored as password hashes, never plaintext.

A code is a verification identity, not a Coveted user account. It can be labeled as:

- a location code, assigned to one location
- an employee code, assigned to one location or usable across the business

Examples of labels:

- Front Desk
- Bar
- Sarah
- Evening Manager

The person entering the code does not sign into a business account and receives no business permissions.

### Physical claim flow

1. A member opens a business benefit from Inbox.
2. The member selects an eligible location when necessary.
3. The member shows the reward to the person handling the claim.
4. That person enters one 5–10 character claim code on the member's device.
5. Coveted verifies the code against that business/location.
6. Coveted records the claim atomically and stores the exact claim-code identity used.
7. The reward moves to Claim Box.

Claim attribution preserves the location, code type, and code label so a business can distinguish location-level and employee-labeled activity without creating employee accounts.

### Claim Box

Claim Box is the member's completed claim history/receipt area. It is separate from Inbox.

A claim record is never erased when it is reversed.

### Refund

Only a Business Admin for that business or a Coveted System Admin may refund a claim.

A refund:

- marks the existing claim record `refunded`
- records refund time, authorizing admin, and optional reason
- keeps the original location and claim-code attribution intact
- restores the issuance to Inbox when still valid
- otherwise leaves it expired

A refunded reward may be claimed again if it remains valid and its campaign/rules allow the existing issuance to be used again.

## Campaign activity / attribution

Campaign activity records capture lifecycle events such as:

- reward issued
- reward viewed
- reward claimed
- reward refunded
- reward expired

This becomes the internal attribution trail for partner dashboards and return-value analysis.

## Benefits

Coveted calls the member-facing area **Benefits**, not Wallet.

Benefits has two member boxes:

- Inbox
- Claim Box

Benefit categories:

- Gift
- Perk
- Service
- Access
- Music
- Video
- Media
- Mystery

Benefits should preserve provenance wherever possible:

> Unlocked at Candlelight Supper #18

or

> A gift from The Henry

or

> From artist partner Luna Grey

## Audio player

Coveted includes a universal persistent audio player.

Desktop:

- artwork
- artist / title
- play/pause
- timeline
- elapsed time
- close

Mobile:

- compact persistent bar above primary navigation
- tap/play behavior without creating a music-first app shell

There is no V1 Music navigation tab. Audio is discovered from Benefits, Artist Partner pages, and event/post-event surfaces.

## Video

Video benefits use a Coveted-facing viewer/presentation layer. Coveted should not become a general-purpose video platform.

## Architecture rules

- Coveted is a modular monolith for V1.
- Page controllers do not become alternate campaign/reward/claim state machines.
- Campaign authorization is enforced against the campaign owner (platform/group/business/artist).
- Reward template ownership and campaign ownership must match.
- Business data may be modified only by that Business Admin or a Coveted System Admin.
- Entering a claim code does not create an account role or grant business access.
- Multi-record reward/campaign/claim lifecycle changes use transactions.
- Events may trigger multiple campaigns without knowing reward implementation details.
- Claims/refunds mutate the authoritative issuance and preserve audit history.
- Audio/video entitlement comes from the member's reward issuance and reward-media relationship.
- Store all timestamps in UTC; event/venue timezone is presentation context.

## Explicit V1 exclusions

Do not add without a new product decision:

- social feed
- follower/following system
- public popularity metrics
- likes
- public comments
- default DMs/group chat
- public event marketplace
- points/leaderboards
- engagement streaks
- creator-content feed
- generic AI chatbot
- Business Staff account type

## Success metrics

Coveted should optimize for real-world outcomes rather than app time:

- invitation acceptance rate
- RSVP-to-attendance rate
- repeat attendance
- guest-to-member conversion
- second-meeting / reconnect rate
- venue return visits
- member benefit issuance/claim/refund
- claim attribution by location/code identity
- artist media unlock/use
- campaign conversion
- venue/group repeat partnerships
- artist/group repeat partnerships
