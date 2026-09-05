(() => {
    'use strict';

    const admin = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"]');
    if (!admin) return;

    admin.querySelectorAll('a[href^="/host.php?event="]').forEach((link) => {
        // Links inside the canonical Event Workspace intentionally open the
        // separate Host / Check-in operational interface.
        if (link.closest('[data-admin-event-workspace]')) return;

        let url;
        try {
            url = new URL(link.getAttribute('href') || '', window.location.origin);
        } catch (_) {
            return;
        }

        const eventRef = url.searchParams.get('event');
        if (!eventRef) return;

        const next = new URL('/admin/event.php', window.location.origin);
        next.searchParams.set('event', eventRef);
        link.setAttribute('href', next.pathname + next.search);
        link.dataset.adminEventWorkspaceLink = '1';

        const label = (link.textContent || '').trim();
        if (/^manage/i.test(label) || /^open event/i.test(label)) {
            link.textContent = 'Open Workspace →';
        }
    });
})();
