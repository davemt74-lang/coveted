(() => {
    'use strict';

    // Coveted is invite-led for now. Any legacy public registration link is
    // normalized to the request flow, while Sign in remains available.
    document.querySelectorAll('a[href="/auth.php?action=register"]').forEach((link) => {
        link.href = '/request-invite.php';

        const text = link.textContent.trim();
        if (text.startsWith('Join Coveted') || text.startsWith('Create an account') || text.startsWith('Become a partner') || text.startsWith('Join as an artist')) {
            link.innerHTML = 'Request an Invite <span aria-hidden="true">→</span>';
        }
    });

    const citySelect = document.querySelector('[data-city-select]');
    const cityOther = document.querySelector('[data-city-other]');
    const cityOtherInput = document.querySelector('[data-city-other-input]');
    const syncCityOther = () => {
        if (!citySelect || !cityOther || !cityOtherInput) return;
        const isOther = citySelect.value === '0';
        cityOther.hidden = !isOther;
        cityOtherInput.required = isOther;
        if (!isOther) cityOtherInput.value = '';
    };
    if (citySelect) {
        citySelect.addEventListener('change', syncCityOther);
        syncCityOther();
    }

    const dialogs = new Map();
    document.querySelectorAll('[data-dialog]').forEach((dialog) => {
        dialogs.set(dialog.getAttribute('data-dialog'), dialog);
    });

    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = dialogs.get(button.getAttribute('data-dialog-open'));
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        });
    });

    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('dialog');
            if (!dialog) return;
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        });
    });

    document.querySelectorAll('dialog[data-dialog]').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    const crmList = document.querySelector('.cv-crm-list');
    const crmToolbar = document.querySelector('.cv-crm-toolbar');
    const crmMetrics = document.querySelector('.cv-crm-metrics');
    const crmResultsSummary = document.querySelector('.cv-crm-results-summary');
    const crmRecords = crmList ? Array.from(crmList.querySelectorAll('.cv-crm-record')) : [];
    if (!crmList || !crmToolbar || !crmMetrics) return;

    const intelligenceById = new Map();
    const recordById = new Map();
    crmRecords.forEach((record, index) => {
        const input = record.querySelector('input[name="request_id"]');
        const id = Number.parseInt(input?.value || '0', 10);
        if (!Number.isFinite(id) || id < 1) return;
        record.dataset.crmRecordId = String(id);
        record.dataset.crmOriginalIndex = String(index);
        recordById.set(id, record);
    });

    const ids = Array.from(recordById.keys());
    const makeOption = (value, label) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        return option;
    };

    const priorityFilter = document.createElement('select');
    priorityFilter.setAttribute('aria-label', 'Filter by action priority');
    priorityFilter.dataset.crmPriorityFilter = '1';
    [
        ['all', 'All priorities'],
        ['high', 'High priority'],
        ['medium', 'Medium priority'],
        ['low', 'Low / nurture'],
        ['follow_up', 'Follow-up due'],
        ['conversion_ready', 'Conversion ready'],
    ].forEach(([value, label]) => priorityFilter.append(makeOption(value, label)));

    const sortSelect = document.createElement('select');
    sortSelect.setAttribute('aria-label', 'Sort CRM records');
    sortSelect.dataset.crmPrioritySort = '1';
    [
        ['canonical', 'Current order'],
        ['priority', 'Highest priority'],
        ['follow_up', 'Follow-up first'],
        ['oldest', 'Oldest first'],
    ].forEach(([value, label]) => sortSelect.append(makeOption(value, label)));

    const filterButton = crmToolbar.querySelector('button[type="submit"]');
    crmToolbar.insertBefore(priorityFilter, filterButton || null);
    crmToolbar.insertBefore(sortSelect, filterButton || null);

    try {
        priorityFilter.value = sessionStorage.getItem('coveted.crm.priorityFilter.v1') || 'all';
        sortSelect.value = sessionStorage.getItem('coveted.crm.prioritySort.v1') || 'canonical';
    } catch (error) {
        // Storage is optional; intelligence continues without persistence.
    }

    const createMetric = (label, value) => {
        const item = document.createElement('div');
        item.className = 'cv-crm-intelligence-metric';
        const strong = document.createElement('strong');
        strong.textContent = String(value);
        const span = document.createElement('span');
        span.textContent = label;
        item.append(strong, span);
        return item;
    };

    const renderSummary = (summary, scoring) => {
        let panel = document.querySelector('[data-crm-intelligence-summary]');
        if (!panel) {
            panel = document.createElement('section');
            panel.className = 'cv-admin-panel cv-crm-intelligence-summary';
            panel.dataset.crmIntelligenceSummary = '1';
            crmMetrics.insertAdjacentElement('afterend', panel);
        }
        panel.replaceChildren();

        const head = document.createElement('div');
        head.className = 'cv-crm-intelligence-head';
        const copy = document.createElement('div');
        const eyebrow = document.createElement('span');
        eyebrow.className = 'cv-eyebrow';
        eyebrow.textContent = 'CRM INTELLIGENCE';
        const title = document.createElement('h2');
        title.textContent = 'Admin action priority';
        const description = document.createElement('p');
        description.textContent = scoring?.description || 'Deterministic workflow priority from current CRM state.';
        copy.append(eyebrow, title, description);
        const note = document.createElement('span');
        note.className = 'cv-status';
        note.textContent = '0–100 · deterministic';
        head.append(copy, note);

        const grid = document.createElement('div');
        grid.className = 'cv-crm-intelligence-metrics';
        grid.append(
            createMetric('High priority', summary?.high_priority || 0),
            createMetric('Follow-up due', summary?.follow_up_due || 0),
            createMetric('Conversion ready', summary?.conversion_ready || 0),
            createMetric('Aging new', summary?.aging_new || 0)
        );
        panel.append(head, grid);
    };

    const renderRecordIntelligence = (record, intel) => {
        record.dataset.crmPriorityBand = intel.band || 'closed';
        record.dataset.crmPriorityScore = String(Number(intel.score) || 0);
        record.dataset.crmFollowUpDue = intel.follow_up_due ? '1' : '0';
        record.dataset.crmNextAction = intel.next_action_key || 'none';
        record.dataset.crmAgeDays = String(Number(intel.age_days) || 0);

        const tagRow = record.querySelector('.cv-tag-row');
        if (tagRow && !tagRow.querySelector('[data-crm-priority-badge]')) {
            const badge = document.createElement('span');
            badge.dataset.crmPriorityBadge = '1';
            badge.className = `cv-crm-priority-badge is-${intel.band || 'closed'}`;
            badge.textContent = intel.active ? `${intel.score} · ${intel.label}` : intel.label;
            tagRow.append(badge);
        }

        const actions = record.querySelector('.cv-crm-record-actions');
        if (!actions || actions.querySelector('[data-crm-intelligence-card]')) return;
        const card = document.createElement('section');
        card.className = `cv-crm-intelligence-card is-${intel.band || 'closed'}`;
        card.dataset.crmIntelligenceCard = '1';

        const head = document.createElement('div');
        head.className = 'cv-crm-intelligence-card-head';
        const score = document.createElement('strong');
        score.textContent = intel.active ? String(intel.score) : '—';
        const action = document.createElement('div');
        const actionLabel = document.createElement('span');
        actionLabel.textContent = 'NEXT ACTION';
        const actionText = document.createElement('b');
        actionText.textContent = intel.next_action || 'Review record';
        action.append(actionLabel, actionText);
        head.append(score, action);
        card.append(head);

        if (Array.isArray(intel.reasons) && intel.reasons.length) {
            const reasons = document.createElement('ul');
            intel.reasons.slice(0, 5).forEach((reason) => {
                const item = document.createElement('li');
                item.textContent = String(reason);
                reasons.append(item);
            });
            card.append(reasons);
        }

        const disclaimer = document.createElement('small');
        disclaimer.textContent = 'Workflow priority only · not a prediction of personal value or purchase intent.';
        card.append(disclaimer);
        actions.prepend(card);
    };

    const matchesFilter = (intel, filter) => {
        if (filter === 'all') return true;
        if (!intel) return false;
        if (filter === 'follow_up') return Boolean(intel.follow_up_due);
        if (filter === 'conversion_ready') return intel.next_action_key === 'convert';
        return intel.band === filter;
    };

    const applyView = () => {
        const filter = priorityFilter.value || 'all';
        const sort = sortSelect.value || 'canonical';
        const records = Array.from(recordById.entries());

        records.sort(([idA, recordA], [idB, recordB]) => {
            const a = intelligenceById.get(idA) || {};
            const b = intelligenceById.get(idB) || {};
            if (sort === 'priority') {
                return (Number(b.score) || 0) - (Number(a.score) || 0)
                    || Number(recordA.dataset.crmOriginalIndex) - Number(recordB.dataset.crmOriginalIndex);
            }
            if (sort === 'follow_up') {
                return Number(Boolean(b.follow_up_due)) - Number(Boolean(a.follow_up_due))
                    || (Number(b.score) || 0) - (Number(a.score) || 0)
                    || Number(recordA.dataset.crmOriginalIndex) - Number(recordB.dataset.crmOriginalIndex);
            }
            if (sort === 'oldest') {
                return (Number(b.age_days) || 0) - (Number(a.age_days) || 0)
                    || Number(recordA.dataset.crmOriginalIndex) - Number(recordB.dataset.crmOriginalIndex);
            }
            return Number(recordA.dataset.crmOriginalIndex) - Number(recordB.dataset.crmOriginalIndex);
        });

        let visible = 0;
        records.forEach(([id, record]) => {
            crmList.append(record);
            const show = matchesFilter(intelligenceById.get(id), filter);
            record.hidden = !show;
            if (show) visible++;
        });

        if (crmResultsSummary) {
            crmResultsSummary.textContent = `${visible} record${visible === 1 ? '' : 's'} shown`;
        }
        try {
            sessionStorage.setItem('coveted.crm.priorityFilter.v1', filter);
            sessionStorage.setItem('coveted.crm.prioritySort.v1', sort);
        } catch (error) {
            // Optional persistence only.
        }
    };

    priorityFilter.addEventListener('change', applyView);
    sortSelect.addEventListener('change', applyView);

    const endpoint = '/api/admin-crm-intelligence.php';
    const params = new URLSearchParams();
    if (ids.length) params.set('ids', ids.join(','));
    fetch(`${endpoint}?${params.toString()}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    })
        .then(async (response) => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok) throw new Error(data.error || 'CRM intelligence unavailable.');
            return data;
        })
        .then((data) => {
            renderSummary(data.summary || {}, data.scoring || {});
            (Array.isArray(data.items) ? data.items : []).forEach((intel) => {
                const id = Number(intel.id) || 0;
                const record = recordById.get(id);
                if (!record) return;
                intelligenceById.set(id, intel);
                renderRecordIntelligence(record, intel);
            });
            applyView();
        })
        .catch((error) => {
            console.warn('CRM intelligence unavailable:', error);
            priorityFilter.disabled = true;
            sortSelect.disabled = true;
        });
})();