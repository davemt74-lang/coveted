(() => {
    'use strict';

    const app = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"]');
    if (!app) return;

    document.body.classList.add('cv-admin-v2-active');

    const normalize = (value) => (value || '').trim().toLowerCase();
    const slug = (value) => normalize(value).replace(/[^a-z0-9_-]+/g, '-').replace(/^-|-$/g, '');
    const text = (node) => normalize(node?.textContent || '');

    const dropdowns = [...app.querySelectorAll('.cv-admin-dropdown')];
    dropdowns.forEach((details) => {
        details.addEventListener('toggle', () => {
            if (!details.open) return;
            dropdowns.forEach((other) => {
                if (other !== details) other.open = false;
            });
        });
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('.cv-admin-dropdown')) return;
        dropdowns.forEach((details) => {
            details.open = false;
        });
    });

    const pageTitle = normalize(app.querySelector('.cv-admin-header-copy strong')?.textContent || '');

    const makeToolbar = ({className = 'cv-admin-people-toolbar', filters = [], searchPlaceholder = 'Search'}) => {
        const toolbar = document.createElement('div');
        toolbar.className = className;

        const filterSet = document.createElement('div');
        filterSet.className = 'cv-admin-filter-set';
        filterSet.setAttribute('role', 'group');
        filterSet.setAttribute('aria-label', 'Filter list');

        filters.forEach((filter, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.filter = filter.key;
            button.classList.toggle('is-active', index === 0);
            button.innerHTML = `${filter.label} <span>${filter.count}</span>`;
            filterSet.appendChild(button);
        });

        const label = document.createElement('label');
        label.className = 'cv-admin-toolbar-search';
        label.innerHTML = `<span class="sr-only">${searchPlaceholder}</span><input type="search" placeholder="${searchPlaceholder}" autocomplete="off">`;

        toolbar.append(filterSet, label);
        return toolbar;
    };

    const attachFilterBehavior = ({toolbar, rows, matchesFilter, emptyText = 'No records match this filter.', emptyClass = 'cv-admin-filter-empty'}) => {
        const empty = document.createElement('div');
        empty.className = emptyClass;
        empty.hidden = true;
        empty.textContent = emptyText;
        toolbar.insertAdjacentElement('afterend', empty);

        let activeFilter = 'all';
        let searchTerm = '';

        const apply = () => {
            let visible = 0;
            rows.forEach((row) => {
                const filterMatch = activeFilter === 'all' || matchesFilter(row, activeFilter);
                const searchMatch = searchTerm === '' || text(row).includes(searchTerm);
                const show = filterMatch && searchMatch;
                row.hidden = !show;
                if (show) visible += 1;
            });
            empty.hidden = visible !== 0;
        };

        toolbar.querySelectorAll('[data-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                activeFilter = button.getAttribute('data-filter') || 'all';
                toolbar.querySelectorAll('[data-filter]').forEach((candidate) => {
                    candidate.classList.toggle('is-active', candidate === button);
                });
                apply();
            });
        });

        const search = toolbar.querySelector('input[type="search"]');
        search?.addEventListener('input', () => {
            searchTerm = normalize(search.value);
            apply();
        });

        return apply;
    };

    const initEvents = () => {
        if (pageTitle !== 'events') return;

        const cards = [...app.querySelectorAll('.cv-admin-table-card')];
        const eventCard = cards.find((card) => {
            const headers = [...card.querySelectorAll('thead th')].map((th) => text(th));
            return headers.includes('event') && headers.includes('status') && headers.includes('starts');
        });
        if (!eventCard) return;

        const tbody = eventCard.querySelector('tbody');
        const rows = [...(tbody?.querySelectorAll('tr') || [])].filter((row) => row.children.length >= 7);
        if (!rows.length) return;

        rows.forEach((row) => {
            const status = text(row.children[2]);
            row.dataset.adminEventStatus = status;
            row.querySelector('.cv-status')?.classList.add(`is-${slug(status)}`);
        });

        const statuses = ['draft', 'published', 'closed', 'completed', 'cancelled'];
        const filters = [
            {key: 'all', label: 'All', count: rows.length},
            ...statuses
                .map((status) => ({key: status, label: status.charAt(0).toUpperCase() + status.slice(1), count: rows.filter((row) => row.dataset.adminEventStatus === status).length}))
                .filter((filter) => filter.count > 0),
        ];

        const toolbar = makeToolbar({className: 'cv-admin-event-toolbar', filters, searchPlaceholder: 'Search events, groups or status'});
        toolbar.querySelector('.cv-admin-filter-set')?.classList.add('cv-admin-event-filters');
        toolbar.querySelector('.cv-admin-toolbar-search')?.classList.add('cv-admin-event-search');
        toolbar.querySelectorAll('[data-filter]').forEach((button) => {
            button.dataset.status = button.dataset.filter || 'all';
        });
        const scroll = eventCard.querySelector('.cv-admin-table-scroll');
        eventCard.insertBefore(toolbar, scroll || null);
        attachFilterBehavior({
            toolbar,
            rows,
            matchesFilter: (row, filter) => row.dataset.adminEventStatus === filter,
            emptyText: 'No events match this filter.',
            emptyClass: 'cv-admin-event-filter-empty',
        });
    };

    const initUsers = () => {
        if (pageTitle !== 'users') return;

        const createForm = app.querySelector('#create-user');
        if (createForm) createForm.classList.add('cv-admin-create-form');

        const rows = [...app.querySelectorAll('.cv-stack > .cv-admin-row')];
        if (!rows.length) return;
        const stack = rows[0].parentElement;
        stack?.classList.add('cv-admin-people-list');

        rows.forEach((row) => {
            const status = text(row.querySelector('.cv-status'));
            const roles = [...row.querySelectorAll('.cv-pill')].map((pill) => text(pill));
            row.dataset.adminUserStatus = status;
            row.dataset.adminUserRoles = roles.join('|');
            if (status) row.classList.add(`is-${slug(status)}`);
            if (roles.includes('system admin')) row.classList.add('is-system-admin');
        });

        const countRole = (role) => rows.filter((row) => (row.dataset.adminUserRoles || '').split('|').includes(role)).length;
        const countStatus = (status) => rows.filter((row) => row.dataset.adminUserStatus === status).length;
        const filters = [
            {key: 'all', label: 'All', count: rows.length},
            {key: 'active', label: 'Active', count: countStatus('active')},
            {key: 'suspended', label: 'Suspended', count: countStatus('suspended')},
            {key: 'system-admin', label: 'System Admin', count: countRole('system admin')},
            {key: 'attendee-host', label: 'Attendee Host', count: countRole('attendee host')},
            {key: 'artist-partner', label: 'Artist Partner', count: countRole('artist partner')},
        ].filter((filter) => filter.key === 'all' || filter.count > 0);

        const toolbar = makeToolbar({filters, searchPlaceholder: 'Search people, email or role'});
        stack?.insertAdjacentElement('beforebegin', toolbar);
        attachFilterBehavior({
            toolbar,
            rows,
            matchesFilter: (row, filter) => {
                if (filter === 'active' || filter === 'suspended') return row.dataset.adminUserStatus === filter;
                const role = filter.replace(/-/g, ' ');
                return (row.dataset.adminUserRoles || '').split('|').includes(role);
            },
            emptyText: 'No users match this filter.',
        });
    };

    const initRoleRequests = () => {
        if (pageTitle !== 'role requests') return;

        const rows = [...app.querySelectorAll('.cv-stack > .cv-admin-row')];
        if (!rows.length) return;
        const stack = rows[0].parentElement;
        stack?.classList.add('cv-admin-role-queue');

        rows.forEach((row) => {
            const role = text(row.querySelector('.cv-kicker'));
            row.dataset.adminRequestedRole = role;
        });

        const roleCounts = {
            'attendee host': rows.filter((row) => row.dataset.adminRequestedRole === 'attendee host').length,
            'artist partner': rows.filter((row) => row.dataset.adminRequestedRole === 'artist partner').length,
        };

        const summary = document.createElement('div');
        summary.className = 'cv-admin-role-summary';
        summary.innerHTML = `
            <div><span>Pending review</span><strong>${rows.length}</strong></div>
            <div><span>Attendee Host</span><strong>${roleCounts['attendee host']}</strong></div>
            <div><span>Artist Partner</span><strong>${roleCounts['artist partner']}</strong></div>
        `;
        stack?.insertAdjacentElement('beforebegin', summary);

        const filters = [
            {key: 'all', label: 'All', count: rows.length},
            {key: 'attendee-host', label: 'Attendee Host', count: roleCounts['attendee host']},
            {key: 'artist-partner', label: 'Artist Partner', count: roleCounts['artist partner']},
        ].filter((filter) => filter.key === 'all' || filter.count > 0);

        const toolbar = makeToolbar({filters, searchPlaceholder: 'Search requests by person, email or role'});
        summary.insertAdjacentElement('afterend', toolbar);
        attachFilterBehavior({
            toolbar,
            rows,
            matchesFilter: (row, filter) => row.dataset.adminRequestedRole === filter.replace(/-/g, ' '),
            emptyText: 'No role requests match this filter.',
        });
    };

    const initBusinesses = () => {
        if (pageTitle !== 'businesses') return;

        const cards = [...app.querySelectorAll('.cv-admin-table-card')];
        const card = cards.find((candidate) => {
            const headers = [...candidate.querySelectorAll('thead th')].map((th) => text(th));
            return headers.includes('business') && headers.includes('locations') && headers.includes('admins');
        });
        if (!card) return;

        const rows = [...card.querySelectorAll('tbody tr')].filter((row) => row.children.length >= 7);
        if (!rows.length) return;

        rows.forEach((row) => {
            row.classList.add('cv-admin-business-row');
            const status = text(row.children[1]);
            const locations = Number.parseInt(text(row.children[2]), 10) || 0;
            const admins = Number.parseInt(text(row.children[3]), 10) || 0;
            const readiness = locations > 0 && admins > 0 ? 'ready' : 'setup';
            row.dataset.adminBusinessStatus = status;
            row.dataset.adminBusinessReadiness = readiness;
            if (status) row.classList.add(`is-${slug(status)}`);
            row.querySelector('.cv-status')?.classList.add(`is-${slug(status)}`);

            const firstCell = row.children[0];
            if (firstCell && !firstCell.querySelector('.cv-admin-business-health')) {
                const health = document.createElement('span');
                health.className = `cv-admin-business-health is-${readiness}`;
                health.textContent = readiness === 'ready' ? 'Operational' : 'Setup incomplete';
                firstCell.appendChild(health);
            }
        });

        const statusValues = [...new Set(rows.map((row) => row.dataset.adminBusinessStatus).filter(Boolean))];
        const filters = [
            {key: 'all', label: 'All', count: rows.length},
            {key: 'ready', label: 'Operational', count: rows.filter((row) => row.dataset.adminBusinessReadiness === 'ready').length},
            {key: 'setup', label: 'Needs setup', count: rows.filter((row) => row.dataset.adminBusinessReadiness === 'setup').length},
            ...statusValues.map((status) => ({key: `status-${slug(status)}`, label: status.charAt(0).toUpperCase() + status.slice(1), count: rows.filter((row) => row.dataset.adminBusinessStatus === status).length})),
        ].filter((filter) => filter.key === 'all' || filter.count > 0);

        const toolbar = makeToolbar({className: 'cv-admin-business-toolbar', filters, searchPlaceholder: 'Search businesses or status'});
        const scroll = card.querySelector('.cv-admin-table-scroll');
        card.insertBefore(toolbar, scroll || null);
        attachFilterBehavior({
            toolbar,
            rows,
            matchesFilter: (row, filter) => {
                if (filter === 'ready' || filter === 'setup') return row.dataset.adminBusinessReadiness === filter;
                if (filter.startsWith('status-')) return slug(row.dataset.adminBusinessStatus || '') === filter.slice(7);
                return true;
            },
            emptyText: 'No businesses match this filter.',
        });
    };

    const initBusinessLocations = () => {
        const workspaceEyebrow = text(app.querySelector('.cv-page-heading .cv-eyebrow'));
        if (workspaceEyebrow !== 'business workspace') return;

        const activeTab = text(app.querySelector('.cv-tab-row .cv-tab.is-active'));
        if (activeTab !== 'locations') return;

        const cards = [...app.querySelectorAll('.cv-table-card')];
        cards.forEach((card) => {
            const heading = text(card.querySelector('.cv-section-heading .cv-kicker'));
            if (!['locations', 'claim codes'].includes(heading)) return;

            const tbody = card.querySelector('tbody');
            const rows = [...(tbody?.querySelectorAll('tr') || [])];
            if (!rows.length) return;

            const sectionHeading = card.querySelector('.cv-section-heading');
            if (!sectionHeading) return;

            sectionHeading.style.display = 'flex';
            sectionHeading.style.alignItems = 'center';
            sectionHeading.style.gap = '12px';

            const label = document.createElement('label');
            label.className = 'cv-location-search';
            label.innerHTML = `<span class="sr-only">Search ${heading}</span><input type="search" placeholder="Search ${heading}" autocomplete="off">`;
            sectionHeading.appendChild(label);

            const empty = document.createElement('div');
            empty.className = 'cv-admin-filter-empty';
            empty.hidden = true;
            empty.textContent = `No ${heading} match this search.`;
            card.appendChild(empty);

            const input = label.querySelector('input');
            input?.addEventListener('input', () => {
                const query = normalize(input.value);
                let visible = 0;
                rows.forEach((row) => {
                    const show = query === '' || text(row).includes(query);
                    row.hidden = !show;
                    if (show) visible += 1;
                });
                empty.hidden = visible !== 0;
            });
        });
    };

    initEvents();
    initUsers();
    initRoleRequests();
    initBusinesses();
    initBusinessLocations();
})();
