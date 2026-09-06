(() => {
    'use strict';

    const forms = Array.from(document.querySelectorAll('[data-agent-task-execute]'));
    if (!forms.length) return;

    const setBusy = (form, busy) => {
        const button = form.querySelector('button[type="submit"]');
        const select = form.querySelector('select[name="provider"]');
        if (button) {
            if (!button.dataset.idleLabel) button.dataset.idleLabel = button.textContent || 'Run with Agent';
            button.disabled = busy;
            button.textContent = busy ? 'Agent working…' : button.dataset.idleLabel;
        }
        if (select) select.disabled = busy;
        form.dataset.running = busy ? '1' : '0';
    };

    const setStatus = (form, message, isError = false) => {
        const status = form.querySelector('[data-task-execute-status]');
        if (!status) return;
        status.textContent = message || '';
        status.dataset.state = isError ? 'error' : 'status';
    };

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (form.dataset.running === '1') return;

            setBusy(form, true);
            setStatus(form, 'Executing the approved task with the autonomous Agent…');

            try {
                const response = await fetch('/api/admin-agent-task-execute.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                    body: new FormData(form),
                });
                const data = await response.json().catch(() => null);
                if (!data) throw new Error('The task execution response could not be read.');

                if (!response.ok && data.state !== 'blocked') {
                    throw new Error(data.error || 'The autonomous Agent could not run that task.');
                }

                if (data.state === 'completed') {
                    setStatus(form, data.message || 'Task completed.');
                    if (data.thread_href) {
                        window.location.assign(data.thread_href);
                        return;
                    }
                    window.location.reload();
                    return;
                }

                if (data.state === 'running') {
                    setStatus(form, data.message || 'This task is already running.');
                    setBusy(form, false);
                    return;
                }

                if (data.state === 'failed' || data.state === 'blocked') {
                    setStatus(form, data.message || 'The task needs review.', true);
                    window.location.reload();
                    return;
                }

                if (data.ok === true && data.thread_href) {
                    window.location.assign(data.thread_href);
                    return;
                }
                throw new Error(data.error || data.message || 'The autonomous Agent could not run that task.');
            } catch (error) {
                setStatus(form, error instanceof Error ? error.message : 'The autonomous Agent could not run that task.', true);
                setBusy(form, false);
            }
        });
    });
})();
