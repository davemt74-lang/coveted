(() => {
    'use strict';

    const app = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"]');
    if (!app || app.dataset.adminPlatformEnhanced === '1') return;
    app.dataset.adminPlatformEnhanced = '1';

    const normalize = (value) => String(value || '').trim().toLowerCase();
    const numberFrom = (value) => Number.parseInt(String(value || '').replace(/[^0-9-]/g, ''), 10) || 0;
    const pageTitle = normalize(app.querySelector('.cv-admin-header-copy strong')?.textContent || '');

    const platformPages = {
        operations: ['cv-admin-operations-v2', '/admin/operations.php'],
        'landing page': ['cv-admin-landing-v2', '/admin/landing.php'],
        'sample data': ['cv-admin-sample-data-v2', '/admin/sample-data.php'],
        settings: ['cv-admin-settings-v2', '/admin/?view=settings'],
    };

    const platformEntry = platformPages[pageTitle];
    if (platformEntry) app.classList.add(platformEntry[0]);

    const makePlatformLinks = () => {
        if (!platformEntry || app.querySelector('.cv-admin-platform-links')) return;
        const links = document.createElement('nav');
        links.className = 'cv-admin-platform-links';
        links.setAttribute('aria-label', 'Platform administration');
        links.innerHTML = `
            <a href="/admin/operations.php" data-page="operations">Operations</a>
            <a href="/admin/landing.php" data-page="landing page">Landing Page</a>
            <a href="/admin/sample-data.php" data-page="sample data">Sample Data</a>
            <a href="/admin/?view=settings" data-page="settings">Settings</a>
        `;
        links.querySelector(`[data-page="${pageTitle}"]`)?.classList.add('is-active');

        const heading = app.querySelector('.cv-admin-page-head, .cv-page-heading');
        if (heading) heading.insertAdjacentElement('afterend', links);
    };

    const insertStatus = ({anchor, eyebrow, title, detail, state = 'clear', pill}) => {
        if (!anchor || anchor.nextElementSibling?.classList.contains('cv-admin-platform-status')) return null;
        const status = document.createElement('div');
        status.className = `cv-admin-platform-status is-${state}`;
        status.innerHTML = `
            <div class="cv-admin-platform-status-copy">
                <span>${eyebrow}</span>
                <strong>${title}</strong>
                <small>${detail}</small>
            </div>
            <span class="cv-admin-platform-status-pill">${pill}</span>
        `;
        anchor.insertAdjacentElement('afterend', status);
        return status;
    };

    const initOperations = () => {
        if (pageTitle !== 'operations') return;

        const heading = app.querySelector('.cv-page-heading');
        const statCards = [...app.querySelectorAll('.cv-stat-grid > .cv-stat')];
        const attentionLabels = new Set([
            'attention items',
            'role requests',
            'suspended accounts',
            'overdue events',
            'location attention',
            'lifecycle backlog',
            'permanent push failures · 24h',
            'retryable push failures · 24h',
            'stuck deliveries',
        ]);
        const activityLabels = new Set(['claims · 7d', 'refunds · 7d']);

        let attentionCount = 0;
        statCards.forEach((card) => {
            const label = normalize(card.querySelector('span')?.textContent || '');
            const value = numberFrom(card.querySelector('strong')?.textContent || '0');
            if (label === 'attention items') attentionCount = value;

            if (attentionLabels.has(label)) {
                card.classList.add(value > 0 ? 'is-attention' : 'is-clear');
            } else if (activityLabels.has(label)) {
                card.classList.add('is-activity');
            }
        });

        insertStatus({
            anchor: heading,
            eyebrow: 'OPERATING STATUS',
            title: attentionCount > 0 ? `${attentionCount} item${attentionCount === 1 ? '' : 's'} need attention` : 'Launch operations are clear',
            detail: attentionCount > 0
                ? 'Use the sections below to resolve event, account, delivery or lifecycle issues in their canonical workspaces.'
                : 'No current launch-health queue is asking for intervention. Activity metrics remain visible below.',
            state: attentionCount > 0 ? 'attention' : 'clear',
            pill: attentionCount > 0 ? 'Needs review' : 'Healthy',
        });

        app.querySelectorAll('.cv-stack').forEach((stack) => stack.classList.add('cv-admin-platform-list'));
    };

    const initLanding = () => {
        if (pageTitle !== 'landing page') return;

        const heading = app.querySelector('.cv-admin-page-head');
        const panels = [...app.querySelectorAll('.cv-admin-settings-grid > .cv-admin-panel')];
        const states = panels.map((panel) => {
            const status = normalize(panel.querySelector('.cv-status')?.textContent || 'off');
            const enabled = status === 'on';
            panel.classList.add(enabled ? 'is-enabled' : 'is-disabled');
            panel.querySelector('.cv-status')?.classList.add(enabled ? 'is-on' : 'is-off');
            return enabled;
        });

        const publicEventsOn = states[0] === true;
        const sampleModeOn = states[1] === true;
        insertStatus({
            anchor: heading,
            eyebrow: 'PUBLIC LANDING STATUS',
            title: publicEventsOn ? 'Upcoming Events are visible' : 'Upcoming Events are hidden',
            detail: sampleModeOn
                ? 'Synthetic event cards are selected for public preview. No sample records are written to the live database.'
                : 'The landing page is configured to use eligible live published group events when the section is visible.',
            state: publicEventsOn ? 'clear' : 'attention',
            pill: sampleModeOn ? 'Sample mode' : 'Live mode',
        });

        const previewRows = [...app.querySelectorAll('.cv-stack > .cv-admin-row')];
        previewRows[0]?.parentElement?.classList.add('cv-admin-landing-preview-list');
    };

    const initSampleData = () => {
        if (pageTitle !== 'sample data') return;

        const heading = app.querySelector('.cv-admin-page-head');
        const masterPanel = app.querySelector('.cv-admin-panel');
        const status = normalize(masterPanel?.querySelector('.cv-status')?.textContent || 'off');
        const enabled = status === 'on';
        masterPanel?.querySelector('.cv-status')?.classList.add(enabled ? 'is-on' : 'is-off');
        masterPanel?.classList.add(enabled ? 'is-enabled' : 'is-disabled');

        insertStatus({
            anchor: heading,
            eyebrow: 'PREVIEW SAFETY',
            title: enabled ? 'Synthetic Member View is active for System Admins' : 'System Admin Member View is using live data',
            detail: 'Sample mode is generated in memory, is visible only to System Admin Member View, and cannot create invitations, RSVPs, claims, rewards or other live records.',
            state: 'clear',
            pill: enabled ? 'Preview ON' : 'Preview OFF',
        });
    };

    const initSettings = () => {
        if (pageTitle !== 'settings') return;

        const grid = app.querySelector('.cv-admin-settings-grid');
        if (!grid) return;
        [...grid.children].forEach((card, index) => {
            if (!(card instanceof HTMLElement)) return;
            card.dataset.adminSettingsIndex = String(index + 1).padStart(2, '0');
        });

        const heading = app.querySelector('.cv-admin-page-head');
        insertStatus({
            anchor: heading,
            eyebrow: 'PLATFORM CONFIGURATION',
            title: 'Admin settings are intentionally scoped',
            detail: 'Installation identity, administrator profile, PWA health and first-run setup are exposed here. Sensitive database and security values remain outside the UI.',
            state: 'clear',
            pill: 'Protected',
        });
    };

    const initMobileQA = () => {
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            app.querySelectorAll('.cv-admin-dropdown[open]').forEach((details) => {
                details.removeAttribute('open');
            });
        });

        if (window.matchMedia('(max-width: 720px)').matches) {
            const active = app.querySelector('.cv-admin-primary-nav a.is-active');
            active?.scrollIntoView({block: 'nearest', inline: 'center'});
        }

        app.classList.add('cv-admin-mobile-qa-ready');
    };

    makePlatformLinks();
    initOperations();
    initLanding();
    initSampleData();
    initSettings();
    initMobileQA();
})();
