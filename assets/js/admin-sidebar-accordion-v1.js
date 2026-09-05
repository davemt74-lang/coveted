(() => {
    'use strict';

    const app = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"][data-admin-user-key]');
    if (!app) return;

    const nav = app.querySelector('[data-admin-accordion-nav]');
    if (!nav) return;

    const userKey = app.dataset.adminUserKey || 'unknown';
    const storageKey = `coveted.adminNav.v1.${userKey}`;
    const sections = [...nav.querySelectorAll('[data-admin-nav-section]')];

    const readState = () => {
        try {
            const parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (_) {
            return {};
        }
    };

    const state = readState();
    const hasStoredState = Object.keys(state).length > 0;

    const setSection = (section, open, persist = false) => {
        const key = section.dataset.adminNavSection || '';
        const toggle = section.querySelector(`[data-admin-nav-toggle="${CSS.escape(key)}"]`);
        section.classList.toggle('is-open', open);
        toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (persist && key) {
            state[key] = open;
            try {
                localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (_) {}
        }
    };

    sections.forEach((section) => {
        const key = section.dataset.adminNavSection || '';
        const defaultOpen = section.classList.contains('is-open');
        const remembered = Object.prototype.hasOwnProperty.call(state, key) ? state[key] === true : defaultOpen;
        setSection(section, hasStoredState ? remembered : defaultOpen, false);

        const toggle = section.querySelector(`[data-admin-nav-toggle="${CSS.escape(key)}"]`);
        toggle?.addEventListener('click', () => {
            setSection(section, !section.classList.contains('is-open'), true);
        });
    });
})();
