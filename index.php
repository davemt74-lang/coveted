<?php
declare(strict_types=1);

require_once __DIR__ . '/app/events.php';
require_once __DIR__ . '/app/rewards.php';
require_once __DIR__ . '/app/member_home.php';
require_once __DIR__ . '/app/site_settings.php';
require_once __DIR__ . '/app/sample_data.php';
require_once __DIR__ . '/app/member_home_v2.php';

$user = coveted_current_user();
$landingEventsEnabled = false;
$landingSampleEventsEnabled = false;
$landingEvents = [];

if (!$user) {
    $landingPdo = coveted_db();
    $landingEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_EVENTS, false, $landingPdo);
    $landingSampleEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_SAMPLE_EVENTS, false, $landingPdo);

    if ($landingEventsEnabled) {
        if ($landingSampleEventsEnabled) {
            $landingEvents = coveted_sample_landing_events();
        } else {
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
                    $eventTitle = $eventType === 'mystery' ? 'Mystery gathering' : (string)$event['title'];
                    $eventTypeLabel = ucwords(str_replace('_', ' ', $eventType));
                    if (!empty($event['is_sample'])) {
                        $eventTypeLabel = 'Preview · ' . $eventTypeLabel;
                    }
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
$home = coveted_member_home_v2_data($user, $pdo);
$nextEvent = $home['next_event'];
$invitation = $home['invitation'];
$groups = $home['groups'];
$benefits = $home['benefits'];
$reconnects = $home['reconnects'];
?>
<div class="cv-member-home-v2">
    <section class="cv-member-home-intro">
        <div>
            <span class="cv-eyebrow">HOME</span>
            <h1>Good to see you, <?= coveted_e((string)$user['display_name']) ?>.</h1>
            <p>Your next gathering, the people around it and what is waiting afterward.</p>
        </div>
        <?php if ($home['sample_mode']): ?>
            <a class="cv-member-preview-pill" href="/admin/sample-data.php">Sample data · ON</a>
        <?php endif; ?>
    </section>

    <section class="cv-member-home-primary" aria-label="Your next move">
        <article class="cv-member-event-hero <?= empty($nextEvent['image']) ? 'is-image-empty' : '' ?>">
            <?php if ($nextEvent && !empty($nextEvent['image'])): ?>
                <img src="<?= coveted_e((string)$nextEvent['image']) ?>" alt="" loading="eager" decoding="async">
            <?php endif; ?>
            <div class="cv-member-event-shade" aria-hidden="true"></div>
            <div class="cv-member-event-copy">
                <span class="cv-member-overline">YOUR NEXT MOVE</span>
                <?php if ($nextEvent): ?>
                    <h2><?= coveted_e((string)$nextEvent['title']) ?></h2>
                    <p><?= coveted_e(coveted_event_format($nextEvent, 'D, M j · g:i A')) ?></p>
                    <div class="cv-member-event-meta">
                        <?php if (!empty($nextEvent['location'])): ?><span><?= coveted_e((string)$nextEvent['location']) ?></span><?php endif; ?>
                        <?php if (!empty($nextEvent['group'])): ?><span><?= coveted_e((string)$nextEvent['group']) ?></span><?php endif; ?>
                    </div>
                    <a href="/events.php">View gathering <span aria-hidden="true">→</span></a>
                <?php else: ?>
                    <h2>Something worth showing up for will land here.</h2>
                    <p>Accept an invitation and your next gathering becomes the center of Home.</p>
                    <a href="/invitations.php">Check invitations <span aria-hidden="true">→</span></a>
                <?php endif; ?>
            </div>
        </article>

        <aside class="cv-member-next-stack">
            <article class="cv-member-quiet-card">
                <span class="cv-member-overline cv-member-overline-dark">INVITATION</span>
                <?php if ($invitation): ?>
                    <h3><?= coveted_e((string)$invitation['title']) ?></h3>
                    <p><?= coveted_e(coveted_event_format($invitation, 'D, M j · g:i A')) ?></p>
                    <?php if (!empty($invitation['group'])): ?><small><?= coveted_e((string)$invitation['group']) ?></small><?php endif; ?>
                    <a href="/invitations.php">Respond <span aria-hidden="true">→</span></a>
                <?php else: ?>
                    <h3>You're caught up.</h3>
                    <p>New invitations will appear here when someone puts your name on the list.</p>
                    <a href="/invitations.php">Invitations <span aria-hidden="true">→</span></a>
                <?php endif; ?>
            </article>

            <article class="cv-member-rule-card">
                <span class="cv-member-overline">THE COVETED RULE</span>
                <h3>When you arrive, the app disappears.</h3>
                <p>Use Coveted for where, when and RSVP. Use the gathering for people.</p>
            </article>
        </aside>
    </section>

    <section class="cv-member-home-section">
        <div class="cv-member-home-section-head">
            <div><span class="cv-eyebrow">YOUR CIRCLES</span><h2>Groups that are moving.</h2></div>
            <a href="/groups.php">All groups <span aria-hidden="true">→</span></a>
        </div>
        <div class="cv-member-image-grid cv-member-image-grid-three">
            <?php if (!$groups): ?>
                <article class="cv-member-empty-panel"><h3>Your groups will appear here.</h3><p>Membership should feel like belonging, not another dashboard counter.</p></article>
            <?php endif; ?>
            <?php foreach ($groups as $group): ?>
                <a class="cv-member-image-card" href="/groups.php">
                    <div class="cv-member-image-card-media">
                        <?php if (!empty($group['image'])): ?><img src="<?= coveted_e((string)$group['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    </div>
                    <div class="cv-member-image-card-copy">
                        <h3><?= coveted_e((string)$group['name']) ?></h3>
                        <p><?= (int)($group['members'] ?? 0) ?> members<?php if (!empty($group['next'])): ?> · Next: <?= coveted_e((string)$group['next']) ?><?php endif; ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="cv-member-home-split">
        <div class="cv-member-home-section">
            <div class="cv-member-home-section-head">
                <div><span class="cv-eyebrow">FOR YOU</span><h2>Benefits waiting.</h2></div>
                <a href="/benefits.php">All benefits <span aria-hidden="true">→</span></a>
            </div>
            <div class="cv-member-benefit-list">
                <?php if (!$benefits): ?>
                    <article class="cv-member-empty-panel"><h3>Nothing to redeem yet.</h3><p>Benefits unlock from the things you actually attend.</p></article>
                <?php endif; ?>
                <?php foreach ($benefits as $benefit): ?>
                    <a class="cv-member-benefit-card" href="/benefits.php">
                        <div class="cv-member-benefit-media"><?php if (!empty($benefit['image'])): ?><img src="<?= coveted_e((string)$benefit['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></div>
                        <div><span><?= coveted_e((string)($benefit['status'] ?? 'Available')) ?></span><h3><?= coveted_e((string)$benefit['title']) ?></h3><p><?= coveted_e((string)($benefit['value'] ?? 'Member benefit')) ?></p></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cv-member-home-section">
            <div class="cv-member-home-section-head">
                <div><span class="cv-eyebrow">RECONNECT</span><h2>People worth keeping.</h2></div>
                <a href="/reconnect.php">Reconnect <span aria-hidden="true">→</span></a>
            </div>
            <?php if (!$reconnects): ?>
                <article class="cv-member-empty-panel"><h3>People you meet will show up here.</h3><p>Reconnect is built from shared real-world experiences, not follower suggestions.</p></article>
            <?php else: ?>
                <div class="cv-member-people-grid">
                    <?php foreach ($reconnects as $person): ?>
                        <a href="/reconnect.php" class="cv-member-person-card">
                            <img src="<?= coveted_e((string)$person['image']) ?>" alt="" loading="lazy" decoding="async">
                            <div><strong><?= coveted_e((string)$person['name']) ?></strong><span><?= coveted_e((string)$person['context']) ?></span></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (count($home['events']) > 1): ?>
        <section class="cv-member-home-section cv-member-home-upcoming">
            <div class="cv-member-home-section-head">
                <div><span class="cv-eyebrow">COMING UP</span><h2>Keep a little room on the calendar.</h2></div>
                <a href="/events.php">All events <span aria-hidden="true">→</span></a>
            </div>
            <div class="cv-member-image-grid cv-member-image-grid-three">
                <?php foreach ($home['events'] as $event): ?>
                    <a class="cv-member-image-card" href="/events.php">
                        <div class="cv-member-image-card-media"><?php if (!empty($event['image'])): ?><img src="<?= coveted_e((string)$event['image']) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?></div>
                        <div class="cv-member-image-card-copy"><span><?= coveted_e(coveted_event_format($event, 'D, M j')) ?></span><h3><?= coveted_e((string)$event['title']) ?></h3><p><?= coveted_e((string)($event['location'] ?? $event['group'] ?? 'Coveted gathering')) ?></p></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
