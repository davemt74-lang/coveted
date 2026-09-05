(() => {
    'use strict';

    const app = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"]');
    if (!app || app.dataset.adminCommunityCleanup === '1') return;
    app.dataset.adminCommunityCleanup = '1';

    const normalize = (value) => String(value || '').trim().toLowerCase();
    const pageTitle = normalize(app.querySelector('.cv-admin-header-copy strong')?.textContent || '');

    const communityPages = {
        businesses: {
            createId: 'create-business',
            label: 'Business',
            toolbar: '.cv-admin-business-toolbar',
            defaultFilter: 'status-active',
        },
        groups: {
            createId: 'create-group',
            label: 'Group',
            toolbar: '.cv-admin-community-toolbar',
            defaultFilter: 'active',
        },
        events: {
            createId: 'create-event',
            label: 'Event',
            toolbar: '.cv-admin-event-toolbar',
            defaultFilter: 'published',
        },
        artists: {
            createId: 'create-artist',
            label: 'Artist',
            toolbar: '.cv-admin-community-toolbar',
            defaultFilter: 'active',
        },
    };

    const setHash = (hash) => {
        const url = new URL(window.location.href);
        url.hash = hash;
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    };

    const clearHash = (hash) => {
        if (window.location.hash !== hash) return;
        const url = new URL(window.location.href);
        url.hash = '';
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}`);
    };

    const openDialog = (dialog, hash) => {
        if (dialog.open) return;
        setHash(hash);
        document.documentElement.classList.add('cv-admin-dialog-open');
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        requestAnimationFrame(() => {
            dialog.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus({preventScroll: true});
        });
    };

    const closeDialog = (dialog, hash) => {
        if (dialog.open && typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        document.documentElement.classList.remove('cv-admin-dialog-open');
        clearHash(hash);
    };

    const initCommunityCreateDialog = (config) => {
        const source = app.querySelector(`#${config.createId}`);
        if (!source) return;

        app.classList.add('cv-admin-community-list-first');
        source.classList.add('cv-admin-create-dialog-content');

        const sourceTitle = source.querySelector('h2')?.textContent?.trim() || `Create ${config.label}`;
        const dialog = document.createElement('dialog');
        dialog.className = 'cv-admin-create-dialog';
        dialog.setAttribute('aria-label', sourceTitle);
        dialog.dataset.createDialog = config.createId;

        const shell = document.createElement('div');
        shell.className = 'cv-admin-create-dialog-shell';

        const head = document.createElement('div');
        head.className = 'cv-admin-create-dialog-head';
        head.innerHTML = `
            <div>
                <span>CREATE ${config.label.toUpperCase()}</span>
                <strong>${sourceTitle}</strong>
            </div>
            <button class="cv-admin-create-dialog-close" type="button" aria-label="Close create ${config.label.toLowerCase()} dialog">×</button>
        `;

        shell.append(head, source);
        dialog.append(shell);
        app.append(dialog);

        const hash = `#${config.createId}`;
        const closeButton = dialog.querySelector('.cv-admin-create-dialog-close');
        closeButton?.addEventListener('click', () => closeDialog(dialog, hash));

        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeDialog(dialog, hash);
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) closeDialog(dialog, hash);
        });

        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const link = event.target.closest(`a[href*="#${config.createId}"]`);
            if (!link) return;

            const target = new URL(link.href, window.location.href);
            const current = new URL(window.location.href);
            if (target.pathname !== current.pathname || target.search !== current.search) return;

            event.preventDefault();
            openDialog(dialog, hash);
        });

        if (window.location.hash === hash) {
            requestAnimationFrame(() => openDialog(dialog, hash));
        }
    };

    const initActiveDefault = (config) => {
        const activate = () => {
            const toolbar = app.querySelector(config.toolbar);
            const button = toolbar?.querySelector(`[data-filter="${config.defaultFilter}"]`);
            if (button instanceof HTMLButtonElement && !button.classList.contains('is-active')) {
                button.click();
            }
        };

        requestAnimationFrame(() => requestAnimationFrame(activate));
    };

    const initBusinessWorkspace = () => {
        const heading = app.querySelector('.cv-page-heading');
        if (normalize(heading?.querySelector('.cv-eyebrow')?.textContent || '') !== 'business workspace') return;

        app.classList.add('cv-business-workspace-v2');

        const duplicateIdentity = [...app.querySelectorAll('.cv-section-head')].find((section) => {
            return normalize(section.querySelector('.cv-eyebrow')?.textContent || '') === 'current business';
        });

        if (duplicateIdentity) {
            const selector = duplicateIdentity.querySelector('.cv-business-selector');
            if (selector && heading) {
                selector.classList.add('cv-business-selector-v2');
                heading.append(selector);
            }
            duplicateIdentity.remove();
        }

        app.querySelector('.cv-tab-row[aria-label="Business workspace"]')?.classList.add('cv-business-tabs-v2');
    };

    const communityConfig = communityPages[pageTitle];
    if (communityConfig) {
        initCommunityCreateDialog(communityConfig);
        initActiveDefault(communityConfig);
    }

    initBusinessWorkspace();
})();
