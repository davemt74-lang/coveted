(() => {
    'use strict';

    const addMemberDailyLink = () => {
        const nav = document.querySelector('.cv-nav');
        if (!nav || nav.querySelector('a[href="/daily.php"]')) return;

        const eventsLink = nav.querySelector('a[href="/my-events.php"], a[href="/events.php"]');
        if (!eventsLink) return;

        const link = document.createElement('a');
        link.href = '/daily.php';
        link.textContent = 'Daily';
        if (window.location.pathname === '/daily.php') link.classList.add('is-active');
        eventsLink.insertAdjacentElement('afterend', link);
    };

    const addAdminDailyLink = () => {
        const community = document.querySelector('.cv-admin-nav-group[data-admin-nav-key="community"] .cv-admin-nav-body');
        if (!community || community.querySelector('a[href="/admin/daily-events.php"]')) return;

        const eventsLink = community.querySelector('a[href="/admin/?view=events"]');
        const link = document.createElement('a');
        link.href = '/admin/daily-events.php';
        link.innerHTML = '<span class="cv-admin-nav-text">Daily Events</span>';
        if (window.location.pathname === '/admin/daily-events.php') link.classList.add('is-active');

        if (eventsLink) {
            eventsLink.insertAdjacentElement('afterend', link);
        } else {
            community.appendChild(link);
        }
    };

    const addBusinessDailyLink = () => {
        const tabs = document.querySelector('.cv-tab-row[aria-label="Business workspace"]');
        if (!tabs || tabs.querySelector('a[data-business-daily-events]')) return;

        const businessRef = new URLSearchParams(window.location.search).get('business');
        if (!businessRef) return;

        const link = document.createElement('a');
        link.className = 'cv-tab';
        link.dataset.businessDailyEvents = '1';
        link.href = `/business-daily-events.php?business=${encodeURIComponent(businessRef)}`;
        link.textContent = 'Daily Events';
        tabs.appendChild(link);
    };

    addMemberDailyLink();
    addAdminDailyLink();
    addBusinessDailyLink();
})();
