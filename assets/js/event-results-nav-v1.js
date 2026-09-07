(() => {
    'use strict';

    const path = location.pathname;
    const params = new URLSearchParams(location.search);
    const eventRef = (params.get('event') || '').trim();

    if (path.startsWith('/admin')) {
        const eventNav = Array.from(document.querySelectorAll('.cv-admin-primary-nav a')).find((link) => {
            const href = link.getAttribute('href') || '';
            return href === '/admin/?view=events';
        });
        if (eventNav && !document.querySelector('[data-event-results-nav]')) {
            const link = document.createElement('a');
            link.dataset.eventResultsNav = '1';
            link.href = '/admin/event-results.php';
            link.innerHTML = '<span class="cv-admin-nav-text">Event Results</span>';
            if (path === '/admin/event-results.php') link.classList.add('is-active');
            eventNav.insertAdjacentElement('afterend', link);
        }
    }

    if (path === '/admin/event-production.php' && eventRef) {
        const actions = document.querySelector('.cv-admin-page-head .cv-action-row');
        if (actions && !actions.querySelector('[data-event-results-link]')) {
            const link = document.createElement('a');
            link.dataset.eventResultsLink = '1';
            link.className = 'cv-button cv-button-soft';
            link.href = `/admin/event-results.php?event=${encodeURIComponent(eventRef)}`;
            link.textContent = 'Event Results';
            actions.prepend(link);
        }
    }
})();
