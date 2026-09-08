<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

coveted_page_start('About Script');
?>
<div class="cv-about-script">
    <section class="cv-about-hero" aria-labelledby="cv-about-title">
        <div class="cv-about-hero-copy">
            <a class="cv-about-back" href="/">← Coveted</a>
            <span class="cv-about-overline">ABOUT SCRIPT</span>
            <h1 id="cv-about-title">The intelligence layer behind real-world connection.</h1>
            <p>Coveted is more than an events website. The script coordinates people, groups, invitations, gatherings, local partners, benefits and relationship history—then gives members and administrators an AI layer that can understand what is happening and help decide what should happen next.</p>
            <div class="cv-about-actions">
                <a class="cv-about-button cv-about-button-dark" href="/auth.php?action=register">Request an invite <span aria-hidden="true">→</span></a>
                <a class="cv-about-button cv-about-button-light" href="#partners">For businesses + partners</a>
            </div>
            <div class="cv-about-hero-tags" aria-label="Coveted system capabilities">
                <span>MEMBERSHIP</span>
                <span>EVENTS</span>
                <span>RELATIONSHIPS</span>
                <span>PARTNERS</span>
                <span>BENEFITS</span>
                <span>AI</span>
            </div>
        </div>

        <div class="cv-about-agent-demo" aria-label="Social Concierge example">
            <div class="cv-about-agent-bar">
                <span class="cv-about-agent-dot" aria-hidden="true"></span>
                <div><small>YOUR COVETED AGENT</small><strong>Social Concierge</strong></div>
                <span class="cv-about-agent-status">PRIVATE</span>
            </div>
            <div class="cv-about-agent-thread">
                <div class="cv-about-agent-message is-agent">
                    <span>CONCIERGE</span>
                    <p>You have a Thursday invitation that matches the smaller gatherings you have attended recently. Want the details?</p>
                </div>
                <div class="cv-about-agent-message is-user">
                    <span>YOU</span>
                    <p>Yes. And who should I reconnect with?</p>
                </div>
                <div class="cv-about-agent-message is-agent">
                    <span>CONCIERGE</span>
                    <p>You have a mutual reconnect from a recent event, plus a benefit you can use this week. I can help you prepare for the next gathering too.</p>
                </div>
            </div>
            <div class="cv-about-agent-chips">
                <span>What should I attend?</span>
                <span>Reconnect opportunities</span>
                <span>What should I know before I go?</span>
            </div>
        </div>
    </section>

    <section class="cv-about-section cv-about-intro" aria-labelledby="cv-about-does-title">
        <div class="cv-about-section-head">
            <span class="cv-about-overline cv-about-overline-dark">WHAT THE SCRIPT DOES</span>
            <h2 id="cv-about-does-title">One system carries the relationship from invitation to what comes next.</h2>
            <p>Instead of treating an event as a one-time transaction, Coveted keeps the useful context around participation so the network can become smarter without turning people into public scores.</p>
        </div>

        <div class="cv-about-flow" aria-label="Coveted relationship flow">
            <article>
                <span>01</span>
                <strong>Invite</strong>
                <p>Groups, guest passes, invitations and RSVP decisions establish who is expected and why.</p>
            </article>
            <div class="cv-about-flow-arrow" aria-hidden="true">→</div>
            <article>
                <span>02</span>
                <strong>Show up</strong>
                <p>Verified attendance separates real participation from clicks, likes and passive interest.</p>
            </article>
            <div class="cv-about-flow-arrow" aria-hidden="true">→</div>
            <article>
                <span>03</span>
                <strong>Continue</strong>
                <p>Benefits, mutual reconnects, membership progress and return opportunities keep value moving after the event.</p>
            </article>
            <div class="cv-about-flow-arrow" aria-hidden="true">→</div>
            <article>
                <span>04</span>
                <strong>Learn</strong>
                <p>Outcome history helps the system improve future event, relationship and partner decisions.</p>
            </article>
        </div>
    </section>

    <section class="cv-about-concierge" id="social-concierge" aria-labelledby="cv-concierge-title">
        <div class="cv-about-concierge-copy">
            <span class="cv-about-overline">FOR MEMBERS</span>
            <h2 id="cv-concierge-title">A social concierge, not another social feed.</h2>
            <p>The member AI is designed to help someone participate in real life. It understands the member’s own invitations, visible events, verified participation history, benefits, notifications and permitted relationship context, then turns that into useful private guidance.</p>
            <blockquote>“What should I do next, who is worth reconnecting with, and what opportunities are already around me?”</blockquote>
        </div>

        <div class="cv-about-benefit-grid">
            <article>
                <span class="cv-about-card-index">01</span>
                <h3>New connections with context</h3>
                <p>Connections begin through shared groups and real gatherings—not follower suggestions. After verified participation, the system can surface permitted mutual reconnect opportunities so a good conversation does not have to disappear when the event ends.</p>
            </article>
            <article>
                <span class="cv-about-card-index">02</span>
                <h3>New social opportunities</h3>
                <p>The concierge can surface invitations and upcoming gatherings that fit the member’s current groups and recent participation patterns, giving people a useful answer to “what should I attend next?”</p>
            </article>
            <article>
                <span class="cv-about-card-index">03</span>
                <h3>Better event preparation</h3>
                <p>Before a gathering, the concierge can organize what the member is allowed to know: timing, revealed location details, group context, +1 availability, current RSVP state and other useful preparation notes.</p>
            </article>
            <article>
                <span class="cv-about-card-index">04</span>
                <h3>Relationship continuity</h3>
                <p>Coveted remembers the member journey across groups, attended gatherings and mutual reconnects. The goal is to help relationships continue naturally instead of resetting to zero after every event.</p>
            </article>
            <article>
                <span class="cv-about-card-index">05</span>
                <h3>Benefits that follow participation</h3>
                <p>Local rewards, media, gifts and other benefits can remain connected to the event or campaign that created them. The concierge can remind a member what is available and what may expire soon.</p>
            </article>
            <article>
                <span class="cv-about-card-index">06</span>
                <h3>Less noise, more useful action</h3>
                <p>There is no requirement to maintain a public content persona. The AI works from private, permissioned context and can help with decisions while the product stays focused on showing up, meeting people and returning to places that matter.</p>
            </article>
        </div>
    </section>

    <section class="cv-about-opportunity" aria-labelledby="cv-opportunity-title">
        <div class="cv-about-opportunity-copy">
            <span class="cv-about-overline cv-about-overline-dark">FROM CONNECTION TO OPPORTUNITY</span>
            <h2 id="cv-opportunity-title">The network can create more than a night out.</h2>
            <p>As members participate, Coveted can help surface the next useful opportunity around the relationship—another gathering, a mutual reconnect, a group invitation, a local benefit, an artist experience or a reason to return to a partner business.</p>
        </div>
        <div class="cv-about-opportunity-map" aria-label="Examples of member opportunity paths">
            <div class="cv-about-opportunity-center"><span>YOU</span><strong>Member journey</strong></div>
            <div class="cv-about-opportunity-node is-one"><small>PEOPLE</small><strong>Mutual reconnect</strong></div>
            <div class="cv-about-opportunity-node is-two"><small>EVENTS</small><strong>Next gathering</strong></div>
            <div class="cv-about-opportunity-node is-three"><small>GROUPS</small><strong>New community</strong></div>
            <div class="cv-about-opportunity-node is-four"><small>PLACES</small><strong>Local return</strong></div>
            <div class="cv-about-opportunity-node is-five"><small>BENEFITS</small><strong>Reward + access</strong></div>
            <div class="cv-about-opportunity-node is-six"><small>CULTURE</small><strong>Artist moment</strong></div>
        </div>
    </section>

    <section class="cv-about-partners" id="partners" aria-labelledby="cv-partners-title">
        <div class="cv-about-section-head">
            <span class="cv-about-overline cv-about-overline-dark">FOR BUSINESSES + PARTNERS</span>
            <h2 id="cv-partners-title">Turn hosting into a continuing customer relationship.</h2>
            <p>Coveted gives approved business and location partners a structured role in the experience while keeping event creation and platform authority with Coveted administration.</p>
        </div>

        <div class="cv-about-partner-grid">
            <article class="is-large">
                <span>REAL-WORLD PARTICIPATION</span>
                <h3>Know the difference between interest and arrival.</h3>
                <p>RSVP and verified attendance context help the platform understand who actually participated. That gives event and partner decisions a stronger signal than impressions alone.</p>
                <div class="cv-about-mini-metrics" aria-hidden="true">
                    <div><small>RSVP</small><strong>42</strong></div>
                    <div><small>ATTENDED</small><strong>35</strong></div>
                    <div><small>RETURN PATHS</small><strong>12</strong></div>
                </div>
            </article>
            <article>
                <span>RETURN VISITS</span>
                <h3>Give people a reason to come back.</h3>
                <p>Partner benefits, campaigns and post-event value can extend a good gathering into another visit instead of ending at checkout.</p>
            </article>
            <article>
                <span>EVENT OPERATIONS</span>
                <h3>A role-aware host workspace.</h3>
                <p>Authorized hosts can work with the events assigned to their business, view relevant RSVP and attendance context, support approved check-in roles and report venue issues without receiving system-wide control.</p>
            </article>
            <article>
                <span>CAMPAIGNS + CULTURE</span>
                <h3>Connect rewards and artists to the experience.</h3>
                <p>Campaigns, benefits and artist participation can be linked to events so the venue becomes part of a larger member experience rather than just an address.</p>
            </article>
            <article>
                <span>BETTER PLANNING</span>
                <h3>Learn from outcomes.</h3>
                <p>Event results, partner activity and return behavior can feed future planning so Coveted administrators can work with partners on what actually produced participation and continued value.</p>
            </article>
        </div>
    </section>

    <section class="cv-about-ai" aria-labelledby="cv-ai-title">
        <div class="cv-about-ai-head">
            <span class="cv-about-overline">AI INTEGRATION</span>
            <h2 id="cv-ai-title">Two AI views. One permission system.</h2>
            <p>The AI does not replace the application’s rules. It sits on top of canonical Coveted services so it can explain, recommend and help execute permitted actions without becoming a second source of truth.</p>
        </div>

        <div class="cv-about-ai-grid">
            <article class="cv-about-ai-card is-member">
                <div class="cv-about-ai-card-head"><span>MEMBER AI</span><strong>Social Concierge</strong></div>
                <ul>
                    <li>Understands the authenticated member’s own journey.</li>
                    <li>Surfaces relevant invitations and gatherings.</li>
                    <li>Helps prepare for the next event.</li>
                    <li>Finds permitted mutual reconnect opportunities.</li>
                    <li>Organizes benefits, notifications and next steps.</li>
                    <li>Requires explicit member confirmation for supported RSVP changes.</li>
                </ul>
                <p class="cv-about-ai-boundary"><strong>Privacy boundary:</strong> it does not expose another member’s private timeline, one-sided reconnect choices, lifecycle state or restricted contact details.</p>
            </article>

            <div class="cv-about-ai-core" aria-label="Coveted permission and service core">
                <span>CANONICAL CORE</span>
                <strong>Permissions<br>+ services<br>+ audit</strong>
                <small>AI can reason over context.<br>The application controls state.</small>
            </div>

            <article class="cv-about-ai-card is-admin">
                <div class="cv-about-ai-card-head"><span>ADMIN AI</span><strong>Operations Agent</strong></div>
                <ul>
                    <li>Reads operational and relationship intelligence across authorized Admin systems.</li>
                    <li>Surfaces event-planning, communication and host opportunities.</li>
                    <li>Flags membership lifecycle, guest conversion and network-growth work.</li>
                    <li>Turns deterministic opportunities into a proactive task queue.</li>
                    <li>Uses aggregate context where exact identity is not needed.</li>
                    <li>Keeps protected state-changing actions behind server-owned authority and approval paths.</li>
                </ul>
                <p class="cv-about-ai-boundary"><strong>Authority boundary:</strong> recommendations are not permission. Roles, explicit approvals and canonical application services remain in control.</p>
            </article>
        </div>
    </section>

    <section class="cv-about-outcomes" aria-labelledby="cv-outcomes-title">
        <div class="cv-about-outcomes-copy">
            <span class="cv-about-overline cv-about-overline-dark">OUTCOMES OVER VANITY METRICS</span>
            <h2 id="cv-outcomes-title">Built to understand participation without ranking people.</h2>
            <p>Coveted can learn from verified attendance, repeat participation, accepted invitations, benefit activity, guest-to-member conversion and referral outcomes. It does not need a public popularity score to make those signals useful.</p>
        </div>
        <div class="cv-about-outcome-list">
            <div><span aria-hidden="true">✓</span><p><strong>Verified participation</strong> instead of passive engagement alone.</p></div>
            <div><span aria-hidden="true">✓</span><p><strong>Relationship history</strong> without follower-count competition.</p></div>
            <div><span aria-hidden="true">✓</span><p><strong>Referral outcomes</strong> without public referral leaderboards or member-value scores.</p></div>
            <div><span aria-hidden="true">✓</span><p><strong>Private recommendations</strong> instead of broadcasting personal opportunity signals.</p></div>
        </div>
    </section>

    <section class="cv-about-cta" aria-labelledby="cv-about-cta-title">
        <span class="cv-about-overline">REAL LIFE, FIRST.</span>
        <h2 id="cv-about-cta-title">The software should make the room better—not become the room.</h2>
        <p>Coveted uses software and AI to coordinate the invitation, prepare the member, support the partner, remember what happened and make the next connection easier. Then, when the gathering begins, the experience belongs to the people who showed up.</p>
        <div class="cv-about-actions">
            <a class="cv-about-button cv-about-button-light" href="/auth.php?action=register">Request an invite <span aria-hidden="true">→</span></a>
            <a class="cv-about-text-link" href="/">Back to Coveted</a>
        </div>
    </section>
</div>
<?php coveted_page_end(); ?>
