(() => {
    'use strict';
    const path = location.pathname;
    const shell = document.querySelector('.cv-admin-app');
    if (!shell || shell.dataset.systemSample === '1') return;

    const usersLink = Array.from(document.querySelectorAll('.cv-admin-primary-nav a')).find((link) => (link.getAttribute('href') || '') === '/admin/?view=users');
    if (usersLink && !document.querySelector('[data-member-journeys-nav]')) {
        const link = document.createElement('a');
        link.dataset.memberJourneysNav = '1';
        link.href = '/admin/member-journeys.php';
        link.innerHTML = '<span class="cv-admin-nav-text">Member Journeys</span>';
        if (path === '/admin/member-journeys.php') link.classList.add('is-active');
        usersLink.insertAdjacentElement('afterend', link);
    }
})();
