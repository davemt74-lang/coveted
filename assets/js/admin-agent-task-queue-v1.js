(() => {
    'use strict';

    const root = document.querySelector('[data-admin-agent]');
    if (!root) return;

    const toolbar = root.querySelector('.cv-admin-agent-thread-actions');
    const starters = root.querySelector('.cv-admin-agent-starters');
    const form = root.querySelector('[data-agent-form]');
    const input = root.querySelector('[data-agent-input]');

    if (toolbar && !toolbar.querySelector('[data-agent-task-queue-link]')) {
        const link = document.createElement('a');
        link.className = 'cv-button cv-button-soft';
        link.href = '/admin/agent-tasks.php';
        link.dataset.agentTaskQueueLink = '1';
        link.textContent = 'Task Queue';
        toolbar.prepend(link);

        fetch('/api/admin-agent-tasks.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.ok ? response.json() : null)
            .then((data) => {
                if (!data || data.ok !== true) return;
                const total = Number(data.active_total) || 0;
                link.textContent = total > 0 ? `Task Queue · ${total}` : 'Task Queue';
            })
            .catch(() => {});
    }

    if (starters && form && input && !starters.querySelector('[data-agent-task-review-starter]')) {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.agentTaskReviewStarter = '1';
        button.textContent = 'Review task queue';
        button.addEventListener('click', () => {
            if (input.disabled) return;
            input.value = 'Review my current Admin Agent task queue from the server context. Prioritize active tasks by status and P1/P2/P3 priority, explain what should be worked next, and identify any task that conflicts with current live Coveted state. Do not claim to change task statuses.';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            form.requestSubmit();
        });
        starters.append(button);
    }
})();
