(() => {
    'use strict';

    const appTopbar = document.querySelector('.cv-app-topbar');
    if (!appTopbar) return;

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
            if ((href === '/' && current === '/') || (href !== '/' && current === href)) {
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
