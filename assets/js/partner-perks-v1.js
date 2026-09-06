(() => {
    'use strict';

    if (window.location.pathname !== '/venue-relationships.php') {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const business = params.get('business') || '';
    const group = params.get('group') || '';
    const location = params.get('location') || '';
    if (!business || !group || !location) {
        return;
    }

    const actions = document.querySelector('.cv-section-head .cv-member-actions');
    if (!actions || actions.querySelector('[data-partner-perks-link]')) {
        return;
    }

    const link = document.createElement('a');
    link.className = 'cv-button cv-button-soft';
    link.dataset.partnerPerksLink = '1';
    link.textContent = 'Partner Perks';
    link.href = '/partner-perks.php?' + new URLSearchParams({ business, group, location }).toString();
    actions.appendChild(link);
})();
