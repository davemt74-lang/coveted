(() => {
    'use strict';

    const memberEventsLink = document.querySelector('.cv-nav a[href="/events.php"]');
    if (!memberEventsLink) {
        return;
    }

    memberEventsLink.href = '/my-events.php';
    memberEventsLink.textContent = 'My Events';
})();
