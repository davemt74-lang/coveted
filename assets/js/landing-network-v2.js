(() => {
    'use strict';

    const landing = document.querySelector('.cv-public-landing');
    const hero = landing?.querySelector('.cv-landing-hero');
    if (!landing || !hero) return;

    const numberFormatter = new Intl.NumberFormat('en-US');

    const buildCitySection = (cities) => {
        const section = document.createElement('section');
        section.className = 'cv-landing-city-strip';
        section.setAttribute('aria-label', 'Coveted cities');

        const track = document.createElement('div');
        track.className = 'cv-landing-city-track';
        track.setAttribute('tabindex', '0');
        track.setAttribute('aria-label', 'Coveted cities');

        cities.forEach((city) => {
            const name = document.createElement('span');
            name.className = 'cv-landing-city-name';
            name.textContent = String(city?.name ?? '');
            if (name.textContent !== '') track.appendChild(name);
        });

        section.appendChild(track);
        return section;
    };

    const buildStatsSection = (stats) => {
        const labels = [
            ['members', 'Total members'],
            ['events', 'Total events'],
            ['business_partners', 'Business partners'],
            ['connections_made', 'Connections made'],
        ];

        const section = document.createElement('section');
        section.className = 'cv-landing-network-stats';
        section.setAttribute('aria-labelledby', 'cv-landing-network-stats-title');

        const head = document.createElement('div');
        head.className = 'cv-landing-network-stats-head';
        head.innerHTML = `
            <div>
                <span class="cv-landing-overline">NETWORK PREVIEW</span>
                <h2 id="cv-landing-network-stats-title">Built for showing up.</h2>
            </div>
            <p>A sample snapshot of how Coveted measures participation across members, gatherings, partners and real connections.</p>
        `;

        const grid = document.createElement('div');
        grid.className = 'cv-landing-stat-grid';

        labels.forEach(([key, label]) => {
            const target = Number(stats[key] ?? 0);
            const item = document.createElement('div');
            item.className = 'cv-landing-stat';
            const value = document.createElement('strong');
            value.textContent = '0';
            value.dataset.countTarget = String(Math.max(0, target));
            const text = document.createElement('span');
            text.textContent = label;
            item.append(value, text);
            grid.appendChild(item);
        });

        const note = document.createElement('div');
        note.className = 'cv-landing-sample-note';
        note.textContent = 'Sample data for preview purposes · live network totals will replace these values';

        section.append(head, grid, note);
        return section;
    };

    const animateCounts = (section) => {
        const nodes = [...section.querySelectorAll('[data-count-target]')];
        if (!nodes.length) return;

        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const run = () => {
            nodes.forEach((node) => {
                const target = Number(node.dataset.countTarget || 0);
                if (reducedMotion) {
                    node.textContent = numberFormatter.format(target);
                    return;
                }

                const started = performance.now();
                const duration = 1250;
                const tick = (now) => {
                    const progress = Math.min(1, (now - started) / duration);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    node.textContent = numberFormatter.format(Math.round(target * eased));
                    if (progress < 1) requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            });
        };

        if (!('IntersectionObserver' in window)) {
            run();
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;
            observer.disconnect();
            run();
        }, { threshold: 0.25 });
        observer.observe(section);
    };

    fetch('/api/landing-network.php', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
    })
        .then((response) => {
            if (!response.ok) throw new Error(`Landing network request failed: ${response.status}`);
            return response.json();
        })
        .then((payload) => {
            if (!payload?.ok) return;

            if (payload.city_strip_enabled && Array.isArray(payload.cities) && payload.cities.length) {
                hero.after(buildCitySection(payload.cities));
            }

            if (payload.network_stats_enabled && payload.stats && typeof payload.stats === 'object') {
                const statsSection = buildStatsSection(payload.stats);
                const appSection = landing.querySelector('.cv-landing-app');
                if (appSection) {
                    appSection.after(statsSection);
                } else {
                    landing.appendChild(statsSection);
                }
                requestAnimationFrame(() => animateCounts(statsSection));
            }
        })
        .catch((error) => console.error('Coveted landing network failed to load:', error));
})();
