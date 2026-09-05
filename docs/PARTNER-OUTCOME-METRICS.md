# Coveted Partner Outcome Metrics

This document defines the V1 read-side contract for Business and Artist Partner Insights.

## Principle

Coveted measures whether real-world gatherings create stronger relationships. Insights are not a social score, popularity ranking, member-ranking system, or engagement-time dashboard.

The analytics layer is read-only. It derives current results from canonical event, attendance, reward, claim, return, campaign, and partner-relationship records. V1 does not create a second analytics ledger or analytics-specific member profile.

## Privacy boundary

Partner Insights may expose aggregate counts, rates, event/group/location labels, campaign labels, and relationship status. It must not expose attendee identity lists, member email addresses, individual event-feedback answers, Mutual Reconnect choices, or hidden recommendation scores.

Private post-event feedback and reconnect choices must not be queried to calculate Business or Artist Partner outcome metrics.

## Business outcomes

For a selected 30-day, 90-day, 12-month, or all-time window, Business Insights may report:

- completed Coveted gatherings at the business
- verified event visits
- unique attendees
- repeat attendees and repeat-attendance rate
- Coveted groups hosted
- business benefits delivered and members reached
- benefit opens, claims, refunds, and issuance-to-claim rate
- verified venue returns
- verified guest-origin returns
- distinct returning members and return rate
- location-level event/visit/claim/return aggregates
- campaign delivery/use/refund aggregates
- venue relationship status mix

A verified return is classified by the canonical Relationship Return Engine. The Insights service must not invent a parallel return definition.

## Artist outcomes

For the same selectable windows, Artist Partner Insights may report:

- completed Coveted appearances
- verified audience visits
- unique audience
- repeat audience and repeat-audience rate
- Coveted groups reached
- artist media benefits delivered
- unique reward recipients
- benefits opened and open rate
- appearance-level aggregate attendance and reward results
- campaign delivery/open aggregates
- artist/group relationship status mix

V1 does not claim that an opened media benefit proves playback completion. Until Coveted has a canonical media-use event, `viewed_at` is the honest V1 proxy for an opened/unlocked benefit.

## Rate denominators

- Repeat attendance rate = distinct people attending at least two qualifying completed events / distinct qualifying attendees.
- Business claim rate = historical claims / non-cancelled business benefit issuances in the selected period.
- Business return rate = distinct members with a canonical verified return / distinct qualifying event attendees.
- Artist reward open rate = artist reward issuances with `viewed_at` / non-cancelled artist reward issuances.

Zero-denominator rates render as `0.0%`; they do not produce null, infinity, or synthetic values.

## Authority

- Business Insights require scoped Business Admin visibility for that business, or System Admin authority.
- Artist Insights require Artist Partner workspace approval plus an artist-team permission for that artist, or System Admin authority.
- The Insights service has no mutation API.

## Implementation rule

`app/outcomes.php` is the canonical V1 aggregate read side. Business and Artist pages may render its result but must not duplicate its metric definitions in page-controller SQL.
