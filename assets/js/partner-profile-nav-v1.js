(() => {
    'use strict';

    if (window.location.pathname !== '/venue-relationships.php') return;

    const toProfileHref = (href) => {
        try {
            const url = new URL(href, window.location.origin);
            if (!url.searchParams.get('business') || !url.searchParams.get('group') || !url.searchParams.get('location')) return '';
            url.pathname = '/partner-profile.php';
            return url.pathname + '?' + url.searchParams.toString();
        } catch (_) {
            return '';
        }
    };

    document.querySelectorAll('a[href*="venue-relationships.php"][href*="group="][href*="location="]').forEach((link) => {
        if ((link.textContent || '').trim() !== 'Open Relationship') return;
        const href = toProfileHref(link.getAttribute('href') || '');
        if (!href) return;
        link.href = href;
        link.textContent = 'Open Partner Profile';
    });

    const params = new URLSearchParams(window.location.search);
    if (!params.get('business') || !params.get('group') || !params.get('location')) return;
    const detailHead = [...document.querySelectorAll('.cv-section-head')].find((node) =>
        (node.querySelector('.cv-eyebrow')?.textContent || '').trim() === 'RELATIONSHIP DETAIL'
    );
    if (!detailHead || detailHead.querySelector('[data-partner-profile-link]')) return;

    const actions = detailHead.querySelector('.cv-member-actions') || detailHead;
    const link = document.createElement('a');
    link.className = 'cv-button cv-button-primary';
    link.dataset.partnerProfileLink = '1';
    link.href = '/partner-profile.php?' + params.toString();
    link.textContent = 'Partner Profile';
    actions.appendChild(link);
})();