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
    const csrf = root.dataset.csrf || '';
    const storageKey = 'coveted.adminAgent.v1';
    const maxStoredMessages = 24;
    let busy = false;
    let messages = [];

    const requestedNewChat = (() => {
        try {
            const url = new URL(window.location.href);
            const wantsNew = url.searchParams.get('new') === '1';
            if (wantsNew) {
                sessionStorage.removeItem(storageKey);
                url.searchParams.delete('new');
                window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
            }
            return wantsNew;
        } catch (_) {
            return false;
        }
    })();

    const load = () => {
        if (requestedNewChat) {
            messages = [];
            return;
        }
        try {
            const parsed = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            if (Array.isArray(parsed)) {
                messages = parsed
                    .filter((entry) => entry && ['user', 'assistant'].includes(entry.role) && typeof entry.content === 'string')
                    .slice(-maxStoredMessages);
            }
        } catch (_) {
            messages = [];
        }
    };

    const save = () => {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(messages.slice(-maxStoredMessages)));
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
        label.textContent = entry.role === 'user' ? 'YOU' : 'COVETED';

        const body = document.createElement('div');
        body.className = 'cv-admin-agent-message-body';
        body.textContent = entry.content;

        article.append(label, body);
        if (entry.role === 'assistant' && entry.meta) {
            const meta = document.createElement('small');
            meta.textContent = entry.meta;
            article.append(meta);
        }
        return article;
    };

    const render = () => {
        canvas.querySelectorAll('.cv-admin-agent-message').forEach((node) => node.remove());
        empty.hidden = messages.length > 0;
        messages.forEach((entry) => canvas.appendChild(makeMessage(entry)));
        if (messages.length) scrollToLatest();
    };

    const setBusy = (value, message = '') => {
        busy = value;
        if (input) input.disabled = value || !provider || provider.disabled;
        const submit = form?.querySelector('[type="submit"]');
        if (submit) submit.disabled = value || !provider || provider.disabled;
        if (status) status.textContent = message || (value ? 'Thinking…' : 'Ready');
        root.classList.toggle('is-thinking', value);
    };

    const resizeInput = () => {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = `${Math.min(Math.max(input.scrollHeight, 24), 160)}px`;
    };

    const send = async (text) => {
        if (busy || !provider || provider.disabled) return;
        const trimmed = String(text || '').trim();
        if (!trimmed) return;

        const history = messages.slice(-20).map(({ role, content }) => ({ role, content }));
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
            setBusy(false, 'Ready');
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
        setBusy(false, provider?.disabled ? 'Provider required' : 'Ready');
        input?.focus();
    });
})();
