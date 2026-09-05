(() => {
    'use strict';

    const root = document.querySelector('.cv-member-home-v2');
    if (!root) return;

    document.body.classList.add('cv-member-v2-active');

    const nav = document.querySelector('.cv-nav');
    if (nav) {
        const items = [
            ['Home', '/'],
            ['Invitations', '/invitations.php'],
            ['Events', '/events.php'],
            ['Groups', '/groups.php'],
            ['Benefits', '/benefits.php'],
            ['Reconnect', '/reconnect.php'],
            ['Profile', '/profile.php'],
        ];

        const current = window.location.pathname || '/';
        nav.replaceChildren(...items.map(([label, href]) => {
            const link = document.createElement('a');
            link.href = href;
            link.textContent = label;
            const target = href === '/' ? '/' : href;
            if ((target === '/' && current === '/') || (target !== '/' && current === target)) {
                link.classList.add('is-active');
                link.setAttribute('aria-current', 'page');
            }
            return link;
        }));
    }

    document.querySelectorAll('.cv-member-admin-link, .cv-global-create-menu').forEach((node) => node.remove());

    const adminButton = document.querySelector('.cv-app-admin-button');
    if (adminButton) {
        adminButton.textContent = 'Admin';
        adminButton.setAttribute('title', 'Open Control Center');
    }
})();
