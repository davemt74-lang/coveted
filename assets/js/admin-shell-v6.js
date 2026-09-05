(() => {
    'use strict';

    const app = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"]');
    if (!app) return;

    const adminUser = app.dataset.adminUser || 'unknown';
    const groups = [...app.querySelectorAll('details.cv-admin-nav-group[data-admin-nav-key]')];
    const storageKey = `coveted.adminNav.v1.${adminUser}`;

    let saved = {};
    try {
        const parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) saved = parsed;
    } catch (_) {}

    groups.forEach((group) => {
        const key = group.dataset.adminNavKey || '';
        const defaultOpen = group.dataset.adminNavDefault === 'open';

        if (Object.prototype.hasOwnProperty.call(saved, key)) {
            group.open = saved[key] === true;
        } else {
            group.open = defaultOpen;
        }

        group.addEventListener('toggle', () => {
            saved[key] = group.open;
            try {
                localStorage.setItem(storageKey, JSON.stringify(saved));
            } catch (_) {}
        });
    });
})();
