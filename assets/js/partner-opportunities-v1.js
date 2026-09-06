(() => {
    'use strict';

    if (window.location.pathname !== '/venue-relationships.php') return;

    const params = new URLSearchParams(window.location.search);
    const business = (params.get('business') || '').trim();
    if (!business) return;

    const group = (params.get('group') || '').trim();
    const location = (params.get('location') || '').trim();

    const endpoint = new URL('/api/partner-opportunities.php', window.location.origin);
    endpoint.searchParams.set('business', business);
    if (group && location) {
        endpoint.searchParams.set('group', group);
        endpoint.searchParams.set('location', location);
    }

    const make = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = String(text);
        return node;
    };

    const render = (payload) => {
        if (!payload || payload.ok !== true || !Array.isArray(payload.recommendations)) return;
        if (document.querySelector('[data-partner-opportunities]')) return;

        const section = make('section', 'cv-stack');
        section.dataset.partnerOpportunities = 'true';
        section.setAttribute('aria-label', 'Partner opportunities');

        const head = make('div', 'cv-section-head');
        const copy = make('div');
        copy.appendChild(make('span', 'cv-eyebrow', 'PARTNER OPPORTUNITIES'));
        copy.appendChild(make('h2', '', group && location ? 'Recommended next actions' : 'Relationship opportunities'));
        copy.appendChild(make('p', '', 'Evidence-backed recommendations from existing venue, Daily Event, reward and return activity. Recommendations never change relationship or event state automatically.'));
        head.appendChild(copy);

        const count = payload.recommendations.length;
        head.appendChild(make('span', 'cv-status', `${count} opportunit${count === 1 ? 'y' : 'ies'}`));
        section.appendChild(head);

        if (count === 0) {
            const empty = make('div', 'cv-card cv-empty');
            empty.appendChild(make('h3', '', 'No current partner opportunity is flagged.'));
            empty.appendChild(make('p', '', 'The current relationship data does not cross a deterministic recommendation threshold. Continue normal partner and event operations.'));
            section.appendChild(empty);
        } else {
            const list = make('div', 'cv-list');
            payload.recommendations.slice(0, 12).forEach((item) => {
                if (!item || typeof item !== 'object') return;

                const card = make('article', 'cv-card cv-copy-card');
                const tagRow = make('div', 'cv-tag-row');
                tagRow.appendChild(make('span', 'cv-status', `P${Math.max(1, Math.min(3, Number(item.priority) || 2))}`));
                if (item.location_name) tagRow.appendChild(make('span', 'cv-pill', item.location_name));
                if (item.group_name) tagRow.appendChild(make('span', 'cv-pill', item.group_name));
                card.appendChild(tagRow);

                card.appendChild(make('h3', '', item.title || 'Partner opportunity'));
                if (item.detail) card.appendChild(make('p', '', item.detail));
                if (item.evidence) {
                    const evidence = make('p', 'cv-muted');
                    evidence.appendChild(make('strong', '', 'Evidence: '));
                    evidence.appendChild(document.createTextNode(String(item.evidence)));
                    card.appendChild(evidence);
                }

                const href = typeof item.href === 'string' && item.href.startsWith('/') && !item.href.startsWith('//')
                    ? item.href
                    : '';
                if (href) {
                    const actions = make('div', 'cv-action-row');
                    const link = make('a', 'cv-button cv-button-soft', item.action_label || 'Review opportunity');
                    link.href = href;
                    actions.appendChild(link);
                    card.appendChild(actions);
                }

                list.appendChild(card);
            });
            section.appendChild(list);
        }

        const relationshipDetail = group && location ? document.querySelector('.cv-two-column') : null;
        if (relationshipDetail && relationshipDetail.parentNode) {
            relationshipDetail.parentNode.insertBefore(section, relationshipDetail);
            return;
        }

        const feature = document.querySelector('article.cv-feature-card');
        if (feature && feature.parentNode) {
            feature.insertAdjacentElement('afterend', section);
            return;
        }

        const heading = document.querySelector('.cv-page-heading');
        if (heading) heading.insertAdjacentElement('afterend', section);
    };

    fetch(endpoint.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
    })
        .then((response) => response.ok ? response.json() : null)
        .then(render)
        .catch((error) => console.warn('Partner opportunities unavailable:', error));
})();
