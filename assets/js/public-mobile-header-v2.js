(() => {
    'use strict';

    if (!document.body.classList.contains('cv-public-home')) return;

    const headerActions = document.querySelector('.cv-header-actions');
    if (!headerActions) return;

    const signIn = headerActions.querySelector('a[href="/auth.php?action=login"]');
    const invite = headerActions.querySelector('a[href="/auth.php?action=register"]');
    if (!signIn || !invite) return;

    signIn.classList.add('cv-public-desktop-signin');
    invite.classList.add('cv-public-invite-link');

    if (headerActions.querySelector('.cv-public-mobile-menu')) return;

    const menu = document.createElement('details');
    menu.className = 'cv-public-mobile-menu';

    const summary = document.createElement('summary');
    summary.setAttribute('aria-label', 'Open sign in menu');
    summary.innerHTML = '<span class="cv-public-mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>';

    const drawer = document.createElement('div');
    drawer.className = 'cv-public-mobile-drawer';

    const label = document.createElement('span');
    label.className = 'cv-public-mobile-drawer-label';
    label.textContent = 'Member access';

    const login = document.createElement('a');
    login.className = 'cv-public-mobile-drawer-login';
    login.href = '/auth.php?action=login';
    login.innerHTML = '<span>Sign in</span><span aria-hidden="true">→</span>';

    drawer.append(label, login);
    menu.append(summary, drawer);
    headerActions.append(menu);

    menu.addEventListener('toggle', () => {
        summary.setAttribute('aria-label', menu.open ? 'Close sign in menu' : 'Open sign in menu');
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            summary.focus();
        }
    });
})();
