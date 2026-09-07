(() => {
    'use strict';

    const sampleMode = document.querySelector('[data-admin-shell][data-system-sample="1"]') !== null;
    const eventHref = sampleMode
        ? '/admin/system-preview.php?view=events'
        : '/admin/event-opportunities.php';

    const eventsLink = document.querySelector('.cv-admin-sidebar a[href="/admin/?view=events"], .cv-admin-sidebar a[href="/admin/system-preview.php?view=events"]');
    if (eventsLink && !document.querySelector('[data-event-opportunities-nav]')) {
        const link = document.createElement('a');
        link.href = eventHref;
        link.dataset.eventOpportunitiesNav = '1';
        link.innerHTML = '<span class="cv-admin-nav-text">Event Opportunities</span>';
        if (location.pathname === '/admin/event-opportunities.php') {
            link.classList.add('is-active');
        }
        eventsLink.insertAdjacentElement('afterend', link);
    }

    const tabs = document.querySelector('.cv-admin-event-tabs');
    if (tabs && !tabs.querySelector('[data-event-production-tab]')) {
        const params = new URLSearchParams(location.search);
        const eventRef = (params.get('event') || '').trim();
        if (eventRef) {
            const link = document.createElement('a');
            link.href = sampleMode
                ? '/admin/system-preview.php?view=events'
                : `/admin/event-production.php?event=${encodeURIComponent(eventRef)}`;
            link.dataset.eventProductionTab = '1';
            link.textContent = 'Production';
            tabs.appendChild(link);
        }
    }

    const eventTopActions = document.querySelector('.cv-admin-event-top-actions');
    if (eventTopActions && !eventTopActions.querySelector('[data-event-production-action]')) {
        const params = new URLSearchParams(location.search);
        const eventRef = (params.get('event') || '').trim();
        if (eventRef) {
            const link = document.createElement('a');
            link.className = 'cv-button cv-button-soft';
            link.dataset.eventProductionAction = '1';
            link.href = sampleMode
                ? '/admin/system-preview.php?view=events'
                : `/admin/event-production.php?event=${encodeURIComponent(eventRef)}`;
            link.textContent = 'Production';
            eventTopActions.prepend(link);
        }
    }
})();
