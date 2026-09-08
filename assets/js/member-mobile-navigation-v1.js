(() => {
    'use strict';

    const appTopbar = document.querySelector('.cv-app-topbar');
    const drawer = document.querySelector('body:not(.cv-admin-body) .cv-header');
    const nav = drawer?.querySelector('.cv-nav');
    if (!appTopbar || !drawer || !nav) return;

    const mobileQuery = window.matchMedia('(max-width: 820px)');
    const drawerId = 'cv-member-navigation-drawer';
    const navId = 'cv-member-primary-nav';
    drawer.id = drawer.id || drawerId;
    nav.id = nav.id || navId;

    if (document.querySelector('.cv-member-menu-toggle')) return;

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'cv-member-menu-toggle';
    toggle.setAttribute('aria-label', 'Open navigation');
    toggle.setAttribute('aria-controls', drawer.id);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = '<span class="cv-member-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>';

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'cv-member-menu-close';
    closeButton.setAttribute('aria-label', 'Close navigation');
    closeButton.innerHTML = '<span aria-hidden="true">×</span>';

    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'cv-member-nav-backdrop';
    backdrop.setAttribute('aria-label', 'Close navigation');
    backdrop.tabIndex = -1;

    appTopbar.insertBefore(toggle, appTopbar.firstChild);
    drawer.appendChild(closeButton);
    appTopbar.insertAdjacentElement('afterend', backdrop);

    let restoreFocus = false;

    const focusableInDrawer = () => Array.from(drawer.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((element) => !element.hasAttribute('hidden') && element.getClientRects().length > 0);

    const setOpen = (open, options = {}) => {
        const shouldOpen = Boolean(open && mobileQuery.matches);
        const wasOpen = document.body.classList.contains('cv-member-nav-open');

        document.body.classList.toggle('cv-member-nav-open', shouldOpen);
        toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', shouldOpen ? 'Close navigation' : 'Open navigation');
        drawer.setAttribute('aria-hidden', shouldOpen || !mobileQuery.matches ? 'false' : 'true');

        if (shouldOpen && !wasOpen) {
            restoreFocus = options.restoreFocus !== false;
            window.requestAnimationFrame(() => {
                const activeLink = nav.querySelector('[aria-current="page"]');
                (activeLink || closeButton).focus();
            });
        } else if (!shouldOpen && wasOpen && restoreFocus && options.focusToggle !== false) {
            restoreFocus = false;
            window.requestAnimationFrame(() => toggle.focus());
        }
    };

    const close = (focusToggle = true) => setOpen(false, { focusToggle });

    toggle.addEventListener('click', () => {
        const open = !document.body.classList.contains('cv-member-nav-open');
        setOpen(open);
    });

    closeButton.addEventListener('click', () => close(true));
    backdrop.addEventListener('click', () => close(true));

    nav.addEventListener('click', (event) => {
        if (event.target.closest('a[href]') && mobileQuery.matches) {
            close(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!document.body.classList.contains('cv-member-nav-open')) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
            return;
        }

        if (event.key !== 'Tab') return;
        const focusable = focusableInDrawer();
        if (focusable.length === 0) {
            event.preventDefault();
            closeButton.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const syncViewport = () => {
        if (!mobileQuery.matches) {
            document.body.classList.remove('cv-member-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Open navigation');
            drawer.removeAttribute('aria-hidden');
            restoreFocus = false;
            return;
        }

        if (!document.body.classList.contains('cv-member-nav-open')) {
            drawer.setAttribute('aria-hidden', 'true');
        }
    };

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', syncViewport);
    } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(syncViewport);
    }
    window.addEventListener('resize', syncViewport, { passive: true });

    syncViewport();
})();
