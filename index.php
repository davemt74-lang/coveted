<?php
declare(strict_types=1);

require_once __DIR__ . '/app/events.php';
require_once __DIR__ . '/app/rewards.php';
require_once __DIR__ . '/app/member_home.php';
require_once __DIR__ . '/app/site_settings.php';

$user = coveted_current_user();
$landingEventsEnabled = false;
$landingEvents = [];

if (!$user) {
    $landingPdo = coveted_db();
    $landingEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_EVENTS, false, $landingPdo);

    if ($landingEventsEnabled) {
        try {
            $landingEvents = $landingPdo->query(
                "SELECT e.public_id, e.title, e.event_type, e.timezone, e.starts_at
                 FROM events e
                 WHERE e.status = 'published'
                   AND e.audience = 'group'
                   AND e.starts_at >= UTC_TIMESTAMP()
                 ORDER BY e.starts_at ASC
                 LIMIT 4"
            )->fetchAll();
        } catch (Throwable $e) {
            error_log('Coveted landing events unavailable: ' . $e->getMessage());
            $landingEvents = [];
        }
    }
}

coveted_page_start('Home', 'Home');

if (!$user):
?>
<div class="cv-public-landing">
    <section class="cv-landing-hero" id="about" aria-labelledby="cv-landing-title">
        <div class="cv-landing-hero-media" aria-hidden="true">
            <img src="/assets/images/landing/hero-rooftop.png" width="1672" height="941" fetchpriority="high" alt="">
        </div>
        <div class="cv-landing-hero-shade" aria-hidden="true"></div>

        <div class="cv-landing-hero-copy">
            <span class="cv-landing-overline">REAL LIFE, FIRST</span>
            <h1 id="cv-landing-title">Real people.<br>Extraordinary experiences.</h1>
            <p>Coveted is a private social membership for curated gatherings, meaningful connections and benefits that reward showing up.</p>
            <div class="cv-landing-actions">
                <a class="cv-landing-button cv-landing-button-light" href="/auth.php?action=register">Join Coveted <span aria-hidden="true">→</span></a>
                <a class="cv-landing-text-link" href="/auth.php?action=login">Already a member? Sign in</a>
            </div>
            <div class="cv-landing-hero-meta" aria-label="Coveted experience">
                <span>PRIVATE GATHERINGS</span>
                <span>TRUSTED GROUPS</span>
                <span>LOCAL PLACES</span>
                <span>ARTIST MOMENTS</span>
            </div>
        </div>

        <a class="cv-landing-scroll" href="<?= $landingEventsEnabled ? '#upcoming-events' : '#membership' ?>" aria-label="Explore the Coveted experience">
            <span>DISCOVER</span><span aria-hidden="true">↓</span>
        </a>
    </section>

    <?php if ($landingEventsEnabled): ?>
        <section class="cv-landing-intro" id="upcoming-events" aria-labelledby="cv-upcoming-events-title">
            <div class="cv-landing-intro-grid">
                <div class="cv-landing-section-head">
                    <span class="cv-landing-overline cv-landing-overline-dark">UPCOMING</span>
                    <h2 id="cv-upcoming-events-title">Worth showing up for.</h2>
                </div>
                <div class="cv-landing-intro-copy">
                    <p>A look at what is coming next. Full details stay inside Coveted for members and invited guests.</p>
                </div>
            </div>

            <div class="cv-landing-principles" aria-label="Upcoming Coveted events">
                <?php if (!$landingEvents): ?>
                    <article>
                        <svg viewBox="0 0 32 32" aria-hidden="true"><rect x="5" y="7" width="22" height="20" rx="2"></rect><path d="M10 4v6M22 4v6M5 13h22"></path></svg>
                        <h3>New gatherings are being planned.</h3>
                        <p>Check back soon for the next Coveted experience.</p>
                    </article>
                <?php endif; ?>

                <?php foreach ($landingEvents as $event): ?>
                    <?php
                    $eventType = (string)$event['event_type'];
                    $eventTitle = $eventType === 'mystery'
                        ? 'Mystery gathering'
                        : (string)$event['title'];
                    $eventTypeLabel = ucwords(str_replace('_', ' ', $eventType));
                    ?>
                    <article>
                        <svg viewBox="0 0 32 32" aria-hidden="true"><rect x="5" y="7" width="22" height="20" rx="2"></rect><path d="M10 4v6M22 4v6M5 13h22"></path></svg>
                        <h3><?= coveted_e($eventTitle) ?></h3>
                        <p><?= coveted_e(coveted_event_format($event, 'D, M j · g:i A')) ?><br><?= coveted_e($eventTypeLabel) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="cv-landing-intro" id="membership">
        <div class="cv-landing-intro-grid">
            <div class="cv-landing-section-head">
                <span class="cv-landing-overline cv-landing-overline-dark">A DIFFERENT KIND OF SOCIAL</span>
                <h2>Your invite to more.</h2>
            </div>
            <div class="cv-landing-intro-copy">
                <p>Coveted helps people find the right room, the right people and a reason to come back. The technology stays useful without becoming the experience.</p>
            </div>
        </div>

        <div class="cv-landing-principles" aria-label="What Coveted is built around">
            <article>
                <svg viewBox="0 0 32 32" aria-hidden="true"><rect x="5" y="7" width="22" height="20" rx="2"></rect><path d="M10 4v6M22 4v6M5 13h22"></path></svg>
                <h3>Curated events</h3>
                <p>Private dinners, mystery gatherings, artist sessions and experiences worth leaving the house for.</p>
            </article>
            <article>
                <svg viewBox="0 0 32 32" aria-hidden="true"><circle cx="11" cy="11" r="4"></circle><circle cx="22" cy="12" r="3.5"></circle><path d="M4 27c.5-6 3.2-9 7.5-9s7 3 7.5 9M18 20c1.1-1.1 2.4-1.7 4-1.7 3.8 0 5.8 2.8 6 7"></path></svg>
                <h3>Real communities</h3>
                <p>Groups are built around people who meet in person—not follower counts, feeds or performative engagement.</p>
            </article>
            <article>
                <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 27s10-5.7 10-14a6 6 0 0 0-10-4.5A6 6 0 0 0 6 13c0 8.3 10 14 10 14Z"></path></svg>
                <h3>Member benefits</h3>
                <p>Gifts, media, local rewards and return benefits are tied to participation in the real world.</p>
            </article>
            <article>
                <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 28s9-8.2 9-16a9 9 0 1 0-18 0c0 7.8 9 16 9 16Z"></path><circle cx="16" cy="12" r="3"></circle></svg>
                <h3>Places that matter</h3>
                <p>Local businesses become part of the community through hosting, recognition and reasons to return.</p>
            </article>
        </div>
    </section>

    <section class="cv-landing-app" aria-labelledby="cv-app-title">
        <div class="cv-landing-phone-stage" aria-label="Coveted mobile experience preview">
            <div class="cv-landing-phone-glow" aria-hidden="true"></div>
            <img class="cv-landing-phone cv-landing-phone-home" src="/assets/images/landing/phone-home.png" width="941" height="1672" loading="lazy" decoding="async" alt="Coveted mobile home screen showing invitations and upcoming experiences">
            <img class="cv-landing-phone cv-landing-phone-benefits" src="/assets/images/landing/phone-benefits.png" width="941" height="1672" loading="lazy" decoding="async" alt="Coveted mobile membership benefits screen">
        </div>

        <div class="cv-landing-app-copy">
            <span class="cv-landing-overline cv-landing-overline-dark">THE COVETED APP</span>
            <h2 id="cv-app-title">Everything you need.<br>Nothing you don’t.</h2>
            <p>Invitations, event details, benefits and post-event value live in one quiet place. Coveted helps you get there—and then gets out of the way.</p>

            <div class="cv-landing-app-points">
                <div><strong>Before</strong><span>Invitations, RSVP, where and when.</span></div>
                <div><strong>During</strong><span>Put the phone away. Be there.</span></div>
                <div><strong>After</strong><span>Memories, benefits, reconnects and what comes next.</span></div>
            </div>

            <div class="cv-landing-store-wrap">
                <span>MOBILE APPS COMING SOON</span>
                <div class="cv-landing-store-badges" aria-label="Mobile app stores">
                    <img src="/assets/images/landing/app-store-badge.png" width="815" height="350" loading="lazy" decoding="async" alt="Download on the App Store">
                    <img src="/assets/images/landing/google-play-badge.png" width="822" height="350" loading="lazy" decoding="async" alt="Get it on Google Play">
                </div>
            </div>
        </div>
    </section>

    <section class="cv-landing-network" id="partners" aria-labelledby="cv-network-title">
        <div class="cv-landing-network-head">
            <span class="cv-landing-overline">PEOPLE · PLACES · CULTURE</span>
            <h2 id="cv-network-title">The network is the experience.</h2>
            <p>Coveted brings members, hosts, local businesses and artists into one private real-world community.</p>
        </div>

        <div class="cv-landing-audiences">
            <article>
                <span>01 · MEMBERS</span>
                <h3>Belong somewhere.</h3>
                <p>Meet through trusted groups, accept invitations, show up and build relationships that continue beyond the event.</p>
                <a href="/auth.php?action=register">Join Coveted <span aria-hidden="true">→</span></a>
            </article>
            <article>
                <span>02 · BUSINESSES + HOSTS</span>
                <h3>Give people a reason to return.</h3>
                <p>Create gatherings, host communities and turn a good experience into an ongoing local relationship.</p>
                <a href="/auth.php?action=register">Become a partner <span aria-hidden="true">→</span></a>
            </article>
            <article>
                <span>03 · ARTISTS</span>
                <h3>Turn an audience into a community.</h3>
                <p>Connect performances, media rewards and real gatherings without adding another social feed.</p>
                <a href="/auth.php?action=register">Join as an artist <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

    <section class="cv-landing-manifesto">
        <div class="cv-landing-manifesto-mark">C</div>
        <div class="cv-landing-manifesto-copy">
            <span class="cv-landing-overline cv-landing-overline-dark">THE COVETED RULE</span>
            <h2>When you arrive,<br>the app disappears.</h2>
            <p>Use Coveted for where, when and RSVP. Use the gathering for people.</p>
            <a class="cv-landing-button cv-landing-button-dark" href="/auth.php?action=register">Join Coveted <span aria-hidden="true">→</span></a>
        </div>
    </section>

    <footer class="cv-landing-footer">
        <div>
            <a class="cv-landing-footer-brand" href="/">COVETED</a>
            <span class="cv-landing-footer-tagline">REAL LIFE, FIRST.</span>
        </div>
        <div class="cv-landing-footer-copy">PEOPLE · PLACES · POSSIBILITIES</div>
        <nav aria-label="Footer">
            <a href="#membership">Membership</a>
            <a href="#partners">Partners</a>
            <a href="/auth.php?action=login">Sign in</a>
        </nav>
    </footer>
</div>
<?php
coveted_page_end();
exit;
endif;

$pdo = coveted_db();
$userId = (int)$user['id'];

$eventStmt = $pdo->prepare(
    "SELECT e.public_id, e.title, e.event_type, e.audience, e.timezone, e.starts_at, e.location_visibility, e.status, er.response
     FROM event_rsvps er
     JOIN events e ON e.id = er.event_id
     WHERE er.user_id = ?
       AND er.response = 'attending'
       AND e.status IN ('published','closed')
       AND e.starts_at >= NOW()
     ORDER BY e.starts_at
     LIMIT 1"
);
$eventStmt->execute([$userId]);
$nextEvent = $eventStmt->fetch();
$mysteryReveal = coveted_member_home_mystery_reveal($userId);

$inviteStmt = $pdo->prepare(
    "SELECT ei.public_id, e.title, e.timezone, e.starts_at
     FROM event_invitations ei
     JOIN events e ON e.id = ei.event_id
     WHERE ei.user_id = ?
       AND ei.status = 'pending'
       AND e.status = 'published'
       AND e.starts_at > NOW()
     ORDER BY e.starts_at
     LIMIT 1"
);
$inviteStmt->execute([$userId]);
$nextInvite = $inviteStmt->fetch();

$benefitStmt = $pdo->prepare(
    "SELECT
        ri.public_id,
        ri.status,
        rt.title,
        rt.description,
        rt.reward_type,
        rt.value_text
     FROM reward_issuances ri
     JOIN reward_templates rt ON rt.id = ri.reward_template_id
     WHERE ri.user_id = ?
       AND ri.status NOT IN ('cancelled','expired')
       AND (ri.expires_at IS NULL OR ri.expires_at > NOW())
     ORDER BY ri.issued_at DESC, ri.id DESC
     LIMIT 1"
);
$benefitStmt->execute([$userId]);
$benefit = $benefitStmt->fetch();

$summaryStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*)
         FROM event_rsvps er
         JOIN events e ON e.id = er.event_id
         WHERE er.user_id = ?
           AND er.response = 'attending'
           AND e.status IN ('published','closed')
           AND e.starts_at >= NOW()) AS upcoming_events,
        (SELECT COUNT(*)
         FROM event_invitations ei
         JOIN events e ON e.id = ei.event_id
         WHERE ei.user_id = ?
           AND ei.status = 'pending'
           AND e.status = 'published'
           AND e.starts_at > NOW()) AS pending_invitations,
        (SELECT COUNT(*)
         FROM reward_issuances ri
         WHERE ri.user_id = ?
           AND ri.status NOT IN ('cancelled','expired')
           AND (ri.expires_at IS NULL OR ri.expires_at > NOW())) AS active_benefits,
        (SELECT COUNT(*)
         FROM group_memberships gm
         WHERE gm.user_id = ?
           AND gm.membership_status = 'active') AS active_groups"
);
$summaryStmt->execute([$userId, $userId, $userId, $userId]);
$summary = $summaryStmt->fetch() ?: [
    'upcoming_events' => 0,
    'pending_invitations' => 0,
    'active_benefits' => 0,
    'active_groups' => 0,
];
?>
<section class="cv-page-heading cv-home-heading">
    <span class="cv-eyebrow">HOME</span>
    <h1>Good to see you, <?= coveted_e($user['display_name']) ?>.</h1>
    <p>Only what matters before and after you show up.</p>
</section>

<section class="cv-stat-grid cv-home-stats" aria-label="Member summary">
    <a class="cv-card cv-stat" href="/events.php">
        <strong><?= (int)$summary['upcoming_events'] ?></strong>
        <span>Upcoming events</span>
    </a>
    <a class="cv-card cv-stat" href="/invitations.php">
        <strong><?= (int)$summary['pending_invitations'] ?></strong>
        <span>Invitations</span>
    </a>
    <a class="cv-card cv-stat" href="/benefits.php">
        <strong><?= (int)$summary['active_benefits'] ?></strong>
        <span>Active benefits</span>
    </a>
    <a class="cv-card cv-stat" href="/groups.php">
        <strong><?= (int)$summary['active_groups'] ?></strong>
        <span>Groups</span>
    </a>
</section>

<section class="cv-home-grid">
    <article class="cv-card cv-feature-card">
        <span class="cv-kicker">NEXT</span>

        <?php if ($nextEvent): ?>
            <h2><?= coveted_e($nextEvent['title']) ?></h2>
            <p><?= coveted_e(coveted_event_format($nextEvent, 'D, M j · g:i A')) ?></p>
            <div class="cv-tag-row">
                <span class="cv-pill"><?= $nextEvent['event_type'] === 'mystery' ? 'Mystery gathering' : 'Upcoming gathering' ?></span>
                <?php if ($nextEvent['status'] === 'closed'): ?>
                    <span class="cv-pill">RSVP closed</span>
                <?php endif; ?>
                <?php if ($nextEvent['audience'] === 'invitation_only'): ?>
                    <span class="cv-pill">Invitation only</span>
                <?php endif; ?>
            </div>
            <a class="cv-text-link" href="/events.php">View event →</a>
        <?php else: ?>
            <h2>No upcoming RSVP yet.</h2>
            <p>Your next gathering will appear here when you accept an invitation.</p>
            <a class="cv-text-link" href="/invitations.php">View invitations →</a>
        <?php endif; ?>
    </article>

    <?php if ($mysteryReveal): ?>
        <article class="cv-card">
            <span class="cv-kicker">MYSTERY REVEAL</span>
            <h3><?= coveted_e($mysteryReveal['title'] ?: ucfirst(str_replace('_', ' ', (string)$mysteryReveal['reveal_type']))) ?></h3>
            <p><?= nl2br(coveted_e(mb_strimwidth((string)$mysteryReveal['content'], 0, 260, '…'))) ?></p>
            <a class="cv-text-link" href="/event.php?event=<?= coveted_e(rawurlencode((string)$mysteryReveal['event_public_id'])) ?>">View gathering →</a>
        </article>
    <?php endif; ?>

    <article class="cv-card">
        <span class="cv-kicker">INVITATION</span>

        <?php if ($nextInvite): ?>
            <h3><?= coveted_e($nextInvite['title']) ?></h3>
            <p><?= coveted_e(coveted_event_format($nextInvite, 'D, M j · g:i A')) ?></p>
            <a class="cv-text-link" href="/invitations.php">Respond →</a>
        <?php else: ?>
            <h3>You're caught up.</h3>
            <p>New invitations will appear here.</p>
        <?php endif; ?>
    </article>

    <article class="cv-card">
        <span class="cv-kicker">FOR YOU</span>

        <?php if ($benefit): ?>
            <h3><?= coveted_e($benefit['title']) ?></h3>
            <p><?= coveted_e($benefit['value_text'] ?: ($benefit['description'] ?: 'A Coveted member benefit is waiting for you.')) ?></p>
            <a class="cv-text-link" href="/benefits.php">View benefit →</a>
        <?php else: ?>
            <h3>Your benefits will live here.</h3>
            <p>Gifts, music, access and partner rewards are unlocked through the things you actually attend.</p>
            <a class="cv-text-link" href="/benefits.php">Benefits →</a>
        <?php endif; ?>
    </article>

    <article class="cv-card cv-offline-card">
        <span class="cv-kicker">THE COVETED RULE</span>
        <h3>When you arrive, the app disappears.</h3>
        <p>Use Coveted for where, when and RSVP. Use the gathering for people.</p>
    </article>
</section>
<?php coveted_page_end(); ?>