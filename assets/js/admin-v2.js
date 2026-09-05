(() => {
    'use strict';

    const app = document.querySelector('.cv-admin-app[data-admin-shell="control-center-v5"]');
    if (!app) return;

    document.body.classList.add('cv-admin-v2-active');

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

    const pageTitle = app.querySelector('.cv-admin-header-copy strong')?.textContent?.trim().toLowerCase() || '';
    if (pageTitle !== 'events') return;

    const cards = [...app.querySelectorAll('.cv-admin-table-card')];
    const eventCard = cards.find((card) => {
        const headers = [...card.querySelectorAll('thead th')].map((th) => th.textContent?.trim().toLowerCase() || '');
        return headers.includes('event') && headers.includes('status') && headers.includes('starts');
    });
    if (!eventCard) return;

    const table = eventCard.querySelector('table');
    const tbody = table?.querySelector('tbody');
    if (!table || !tbody) return;

    const rows = [...tbody.querySelectorAll('tr')].filter((row) => row.children.length >= 7);
    if (!rows.length) return;

    const normalizedStatus = (row) => {
        const cell = row.children[2];
        return (cell?.textContent || '').trim().toLowerCase();
    };

    rows.forEach((row) => {
        const status = normalizedStatus(row);
        if (status) {
            row.dataset.adminEventStatus = status;
            row.querySelector('.cv-status')?.classList.add(`is-${status.replace(/[^a-z0-9_-]/g, '-')}`);
        }
    });

    const statuses = ['draft', 'published', 'closed', 'completed', 'cancelled'];
    const counts = Object.fromEntries(statuses.map((status) => [status, rows.filter((row) => normalizedStatus(row) === status).length]));

    const toolbar = document.createElement('div');
    toolbar.className = 'cv-admin-event-toolbar';
    toolbar.innerHTML = `
        <div class="cv-admin-event-filters" role="group" aria-label="Filter events by status">
            <button type="button" class="is-active" data-status="all">All <span>${rows.length}</span></button>
            ${statuses.filter((status) => counts[status] > 0).map((status) => `
                <button type="button" data-status="${status}">${status.charAt(0).toUpperCase() + status.slice(1)} <span>${counts[status]}</span></button>
            `).join('')}
        </div>
        <label class="cv-admin-event-search">
            <span class="sr-only">Search events</span>
            <input type="search" placeholder="Search events, groups or status" autocomplete="off">
        </label>
    `;

    const scroll = eventCard.querySelector('.cv-admin-table-scroll');
    eventCard.insertBefore(toolbar, scroll || null);

    const empty = document.createElement('div');
    empty.className = 'cv-admin-event-filter-empty';
    empty.hidden = true;
    empty.textContent = 'No events match this filter.';
    eventCard.appendChild(empty);

    let activeStatus = 'all';
    let searchTerm = '';

    const applyFilters = () => {
        let visible = 0;
        rows.forEach((row) => {
            const statusMatch = activeStatus === 'all' || normalizedStatus(row) === activeStatus;
            const textMatch = searchTerm === '' || (row.textContent || '').toLowerCase().includes(searchTerm);
            const show = statusMatch && textMatch;
            row.hidden = !show;
            if (show) visible += 1;
        });
        empty.hidden = visible !== 0;
    };

    toolbar.querySelectorAll('[data-status]').forEach((button) => {
        button.addEventListener('click', () => {
            activeStatus = button.getAttribute('data-status') || 'all';
            toolbar.querySelectorAll('[data-status]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
            applyFilters();
        });
    });

    const search = toolbar.querySelector('input[type="search"]');
    search?.addEventListener('input', () => {
        searchTerm = search.value.trim().toLowerCase();
        applyFilters();
    });
})();
