(() => {
    'use strict';

    const root = document.querySelector('[data-admin-agent]');
    if (!root) return;

    const canvas = root.querySelector('[data-agent-canvas]');
    const empty = root.querySelector('[data-agent-empty]');
    const form = root.querySelector('[data-agent-form]');
    const input = root.querySelector('[data-agent-input]');
    const provider = root.querySelector('[data-agent-provider]');
    const status = root.querySelector('[data-agent-status]');
    const newChat = root.querySelector('[data-agent-new-chat]');
    const endpoint = root.dataset.endpoint || '/api/admin-agent-chat.php';
    const activityEndpoint = root.dataset.activityEndpoint || '';
    const csrf = root.dataset.csrf || '';
    const startNew = root.dataset.startNew === '1';
    const storageKey = 'coveted.adminAgent.v1';
    const crmCursorKey = 'coveted.adminAgent.crmCursor.v1';
    const maxStoredMessages = 60;
    const initialCrmCursor = Math.max(0, Number.parseInt(root.dataset.crmCursor || '0', 10) || 0);
    let busy = false;
    let messages = [];
    let crmCursor = initialCrmCursor;
    let crmPolling = false;
    let crmPollingEnabled = activityEndpoint !== '';
    let activityNoticeTimer = 0;

    const defaultStatus = () => (provider?.disabled ? 'Provider required' : 'Ready');

    const load = () => {
        try {
            const parsed = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            if (Array.isArray(parsed)) {
                messages = parsed
                    .filter((entry) => entry
                        && ['user', 'assistant', 'activity'].includes(entry.role)
                        && typeof entry.content === 'string')
                    .slice(-maxStoredMessages);
            }
        } catch (_) {
            messages = [];
        }

        try {
            const savedCursor = Number.parseInt(sessionStorage.getItem(crmCursorKey) || '', 10);
            if (Number.isInteger(savedCursor) && savedCursor >= 0) {
                crmCursor = savedCursor;
            }
        } catch (_) {
            crmCursor = initialCrmCursor;
        }
    };

    const save = () => {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(messages.slice(-maxStoredMessages)));
        } catch (_) {}
    };

    const saveCrmCursor = () => {
        try {
            sessionStorage.setItem(crmCursorKey, String(crmCursor));
        } catch (_) {}
    };

    const scrollToLatest = () => {
        requestAnimationFrame(() => {
            const last = canvas.querySelector('.cv-admin-agent-message:last-child');
            if (last) last.scrollIntoView({ behavior: 'smooth', block: 'end' });
        });
    };

    const makeMessage = (entry) => {
        const article = document.createElement('article');
        article.className = `cv-admin-agent-message is-${entry.role}`;

        const label = document.createElement('span');
        label.className = 'cv-admin-agent-message-label';
        label.textContent = entry.role === 'user' ? 'YOU' : (entry.role === 'activity' ? 'CRM' : 'COVETED');

        const body = document.createElement('div');
        body.className = 'cv-admin-agent-message-body';

        if (entry.role === 'activity') {
            const title = entry.kind === 'newsletter' ? 'New newsletter signup' : 'New CRM submission';
            const identity = [entry.name, entry.city].filter(Boolean).join(' · ');
            const interests = Array.isArray(entry.interests) ? entry.interests.filter(Boolean).join(' · ') : '';
            const lines = [title, identity, interests ? `Interested in: ${interests}` : ''].filter(Boolean);
            body.textContent = lines.join('\n');

            if (typeof entry.href === 'string' && entry.href.startsWith('/')) {
                const link = document.createElement('a');
                link.href = entry.href;
                link.textContent = 'Review in CRM →';
                body.append(document.createTextNode('\n'), link);
            }
        } else {
            body.textContent = entry.content;
        }

        article.append(label, body);
        if (entry.role === 'assistant' && entry.meta) {
            const meta = document.createElement('small');
            meta.textContent = entry.meta;
            article.append(meta);
        }
        if (entry.role === 'activity' && entry.submittedAt) {
            const meta = document.createElement('small');
            meta.textContent = `Received ${entry.submittedAt}`;
            article.append(meta);
        }
        return article;
    };

    const render = (shouldScroll = true) => {
        canvas.querySelectorAll('.cv-admin-agent-message').forEach((node) => node.remove());
        const hasConversation = messages.some((entry) => entry.role === 'user' || entry.role === 'assistant');
        empty.hidden = hasConversation;
        messages.forEach((entry) => canvas.appendChild(makeMessage(entry)));
        if (messages.length && shouldScroll) scrollToLatest();
    };

    const setBusy = (value, message = '') => {
        busy = value;
        if (input) input.disabled = value || !provider || provider.disabled;
        const submit = form?.querySelector('[type="submit"]');
        if (submit) submit.disabled = value || !provider || provider.disabled;
        if (status) status.textContent = message || (value ? 'Thinking…' : defaultStatus());
        root.classList.toggle('is-thinking', value);
    };

    const announceActivity = (count) => {
        if (!status || busy || count < 1) return;
        window.clearTimeout(activityNoticeTimer);
        status.textContent = count === 1 ? '1 new CRM submission' : `${count} new CRM submissions`;
        activityNoticeTimer = window.setTimeout(() => {
            if (!busy && status) status.textContent = defaultStatus();
        }, 8000);
    };

    const resizeInput = () => {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = `${Math.min(Math.max(input.scrollHeight, 24), 160)}px`;
    };

    const appendCrmItems = (items) => {
        if (!Array.isArray(items) || items.length === 0) return 0;
        const seen = new Set(messages
            .filter((entry) => entry.role === 'activity' && typeof entry.activityKey === 'string')
            .map((entry) => entry.activityKey));
        let added = 0;

        items.forEach((item) => {
            const id = Number.parseInt(item?.id, 10);
            if (!Number.isInteger(id) || id < 1) return;
            const activityKey = `crm:${id}`;
            if (seen.has(activityKey)) return;

            messages.push({
                role: 'activity',
                content: item?.kind === 'newsletter' ? 'New newsletter signup' : 'New CRM submission',
                activityKey,
                kind: item?.kind === 'newsletter' ? 'newsletter' : 'invite',
                name: typeof item?.name === 'string' ? item.name : '',
                city: typeof item?.city === 'string' ? item.city : '',
                interests: Array.isArray(item?.interests) ? item.interests.slice(0, 8).map(String) : [],
                submittedAt: typeof item?.submitted_at === 'string' ? item.submitted_at : '',
                href: typeof item?.href === 'string' ? item.href : '/admin/crm.php?status=new',
            });
            seen.add(activityKey);
            added += 1;
        });

        if (messages.length > maxStoredMessages) {
            messages = messages.slice(-maxStoredMessages);
        }
        return added;
    };

    const pollCrm = async () => {
        if (!crmPollingEnabled || crmPolling || document.hidden) return;
        crmPolling = true;
        let continueSoon = false;

        try {
            const url = new URL(activityEndpoint, window.location.origin);
            url.searchParams.set('cursor', String(crmCursor));
            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (response.status === 401 || response.status === 403) {
                crmPollingEnabled = false;
                return;
            }

            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.ok !== true || data.available === false) {
                return;
            }

            const nextCursor = Number.parseInt(data.cursor, 10);
            if (Number.isInteger(nextCursor) && nextCursor >= 0) {
                crmCursor = nextCursor;
                saveCrmCursor();
            }

            const wasNearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 180;
            const added = appendCrmItems(data.items);
            if (added > 0) {
                save();
                render(false);
                if (wasNearBottom) scrollToLatest();
                announceActivity(added);
            }
            continueSoon = data.has_more === true;
        } catch (_) {
            // Polling is intentionally fail-soft. A temporary CRM read failure
            // must never interrupt chat or the Admin Agent workspace.
        } finally {
            crmPolling = false;
        }

        if (continueSoon && crmPollingEnabled && !document.hidden) {
            window.setTimeout(pollCrm, 400);
        }
    };

    const send = async (text) => {
        if (busy || !provider || provider.disabled) return;
        const trimmed = String(text || '').trim();
        if (!trimmed) return;

        const history = messages
            .filter((entry) => entry.role === 'user' || entry.role === 'assistant')
            .slice(-20)
            .map(({ role, content }) => ({ role, content }));
        messages.push({ role: 'user', content: trimmed });
        save();
        render();
        input.value = '';
        resizeInput();
        setBusy(true, 'Thinking…');

        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        body.set('provider', provider.value);
        body.set('message', trimmed);
        body.set('history_json', JSON.stringify(history));

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                credentials: 'same-origin',
                body: body.toString(),
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.ok !== true) {
                throw new Error(data?.error || `Request failed (${response.status}).`);
            }

            const modelMeta = [data.provider === 'openai' ? 'ChatGPT' : 'Claude', data.model].filter(Boolean).join(' · ');
            messages.push({ role: 'assistant', content: String(data.text || ''), meta: modelMeta });
            save();
            render();
            setBusy(false, defaultStatus());
            input.focus();
        } catch (error) {
            const article = document.createElement('article');
            article.className = 'cv-admin-agent-error';
            article.textContent = error instanceof Error ? error.message : 'The Admin Agent request failed.';
            canvas.appendChild(article);
            scrollToLatest();
            setBusy(false, 'Request failed');
        }
    };

    load();
    if (startNew) {
        messages = [];
        save();
        try {
            window.history.replaceState({}, '', '/admin/agent.php');
        } catch (_) {}
    }
    render();
    resizeInput();

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        send(input?.value || '');
    });

    input?.addEventListener('input', resizeInput);
    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form?.requestSubmit();
        }
    });

    root.querySelectorAll('[data-agent-starter]').forEach((button) => {
        button.addEventListener('click', () => send(button.dataset.agentStarter || ''));
    });

    newChat?.addEventListener('click', () => {
        if (busy || !window.confirm('Start a new Admin Agent chat?')) return;
        messages = [];
        save();
        render();
        setBusy(false, defaultStatus());
        input?.focus();
    });

    if (crmPollingEnabled) {
        window.setTimeout(pollCrm, 1500);
        window.setInterval(pollCrm, 60000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) pollCrm();
        });
    }
})();
