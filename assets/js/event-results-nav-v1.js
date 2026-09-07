(() => {
    'use strict';

    const path = location.pathname;
    const params = new URLSearchParams(location.search);
    const eventRef = (params.get('event') || '').trim();
    const sampleMode = document.querySelector('.cv-admin-app[data-system-sample="1"]');

    if (path.startsWith('/admin') && !sampleMode) {
        const eventNav = Array.from(document.querySelectorAll('.cv-admin-primary-nav a')).find((link) => {
            const href = link.getAttribute('href') || '';
            return href === '/admin/?view=events';
        });
        if (eventNav && !document.querySelector('[data-event-results-nav]')) {
            const results = document.createElement('a');
            results.dataset.eventResultsNav = '1';
            results.href = '/admin/event-results.php';
            results.innerHTML = '<span class="cv-admin-nav-text">Event Results</span>';
            if (path === '/admin/event-results.php') results.classList.add('is-active');
            eventNav.insertAdjacentElement('afterend', results);

            const learning = document.createElement('a');
            learning.dataset.eventLearningNav = '1';
            learning.href = '/admin/event-learning.php';
            learning.innerHTML = '<span class="cv-admin-nav-text">Event Learning</span>';
            if (path === '/admin/event-learning.php') learning.classList.add('is-active');
            results.insertAdjacentElement('afterend', learning);
        }
    }

    if (path === '/admin/event-production.php' && eventRef && !sampleMode) {
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

    if (path === '/admin/event-results.php' && !sampleMode) {
        const actions = document.querySelector('.cv-admin-page-head .cv-action-row');
        if (actions && !actions.querySelector('[data-event-learning-link]')) {
            const link = document.createElement('a');
            link.dataset.eventLearningLink = '1';
            link.className = 'cv-button cv-button-soft';
            link.href = '/admin/event-learning.php';
            link.textContent = 'Event Learning';
            actions.prepend(link);
        }
    }
})();
