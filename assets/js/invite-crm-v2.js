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
})();
