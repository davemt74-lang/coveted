(() => {
    'use strict';

    const form = document.querySelector('[data-wave-execution-form]');
    const preview = document.querySelector('[data-wave-preview]');
    if (!form || !preview) return;

    const recipients = Array.from(form.querySelectorAll('[data-wave-recipient]'));
    const maxSelection = Math.max(0, Number.parseInt(form.dataset.maxSelection || '0', 10) || 0);
    const selectedCount = preview.querySelector('[data-wave-selected-count]');
    const projectedLow = preview.querySelector('[data-wave-projected-low]');
    const projectedExpected = preview.querySelector('[data-wave-projected-expected]');
    const projectedHigh = preview.querySelector('[data-wave-projected-high]');
    const send = form.querySelector('[data-wave-send]');
    const selectRecommended = form.querySelector('[data-wave-select-recommended]');
    const clear = form.querySelector('[data-wave-clear]');

    const baseLow = Number(preview.dataset.baseLow || 0);
    const baseExpected = Number(preview.dataset.baseExpected || 0);
    const baseHigh = Number(preview.dataset.baseHigh || 0);
    const lowYield = Number(preview.dataset.lowYield || 0);
    const expectedYield = Number(preview.dataset.expectedYield || 0);
    const highYield = Number(preview.dataset.highYield || 0);
    const capacity = Math.max(0, Number(preview.dataset.capacity || 0));
    const cap = (value) => capacity > 0 ? Math.min(capacity, value) : value;

    const refresh = () => {
        const count = recipients.filter((input) => input.checked).length;
        if (selectedCount) selectedCount.textContent = String(count);
        if (projectedLow) projectedLow.textContent = String(cap(Math.floor(baseLow + (count * lowYield))));
        if (projectedExpected) projectedExpected.textContent = String(cap(Math.round(baseExpected + (count * expectedYield))));
        if (projectedHigh) projectedHigh.textContent = String(cap(Math.ceil(baseHigh + (count * highYield))));
        if (send) {
            send.disabled = count < 1;
            send.textContent = count === 1 ? 'Send 1 Selected Invitation' : `Send ${count} Selected Invitations`;
        }
    };

    recipients.forEach((input) => input.addEventListener('change', () => {
        const checked = recipients.filter((candidate) => candidate.checked);
        if (checked.length > maxSelection) input.checked = false;
        refresh();
    }));

    selectRecommended?.addEventListener('click', () => {
        recipients.forEach((input, index) => { input.checked = index < maxSelection; });
        refresh();
    });
    clear?.addEventListener('click', () => {
        recipients.forEach((input) => { input.checked = false; });
        refresh();
    });

    form.addEventListener('submit', (event) => {
        const count = recipients.filter((input) => input.checked).length;
        if (count < 1 || count > maxSelection) {
            event.preventDefault();
            return;
        }
        const message = form.dataset.confirm || 'Send the selected invitations?';
        if (!window.confirm(message)) event.preventDefault();
    });

    refresh();
})();
