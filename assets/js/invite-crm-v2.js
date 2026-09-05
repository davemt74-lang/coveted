(() => {
    'use strict';

    if (document.body.classList.contains('cv-public-home')) {
        document.querySelectorAll('a[href="/auth.php?action=register"]').forEach((link) => {
            link.href = '/request-invite.php';
            if (link.textContent.trim().startsWith('Join Coveted')) {
                link.innerHTML = 'Request an Invite <span aria-hidden="true">→</span>';
            }
        });
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
