(() => {
    'use strict';

    if (document.querySelector('[data-admin-agent]')) return;
    if (!document.querySelector('.cv-app-topbar') && !document.body.classList.contains('cv-admin-body')) return;
    if (document.querySelector('[data-account-agent-shell]')) return;

    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = '/assets/css/account-agent-shell-v1.css?v=account-agent-shell-v1-20260907';
    document.head.appendChild(css);
    document.body.classList.add('cv-account-agent-active');

    const el = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    };

    const safeInternalPath = (value) => {
        const path = String(value || '').trim();
        return path.startsWith('/') && !path.startsWith('//') ? path : '';
    };

    const root = el('div', 'cv-account-agent-shell');
    root.dataset.accountAgentShell = '1';

    const canvas = el('section', 'cv-account-agent-canvas');
    canvas.hidden = true;
    canvas.setAttribute('aria-label', 'Coveted Agent conversation');

    const head = el('header', 'cv-account-agent-canvas-head');
    const title = el('div', 'cv-account-agent-title');
    title.appendChild(el('div', 'cv-account-agent-mark', 'C'));
    const titleCopy = el('div', 'cv-account-agent-title-copy');
    const titleStrong = el('strong', '', 'Coveted Agent');
    const roleSmall = el('small', '', 'Loading account context…');
    titleCopy.append(titleStrong, roleSmall);
    title.appendChild(titleCopy);
    const headActions = el('div', 'cv-account-agent-head-actions');
    const newButton = el('button', 'cv-account-agent-icon-button', 'New Chat');
    newButton.type = 'button';
    const closeButton = el('button', 'cv-account-agent-icon-button', 'Close');
    closeButton.type = 'button';
    headActions.append(newButton, closeButton);
    head.append(title, headActions);

    const history = el('div', 'cv-account-agent-history');
    history.hidden = true;
    const historyLabel = el('span', 'cv-account-agent-message-label', 'Recent chats');
    const historySelect = document.createElement('select');
    historySelect.setAttribute('aria-label', 'Recent Agent chats');
    history.append(historyLabel, historySelect);

    const notice = el('div', 'cv-account-agent-notice');
    notice.hidden = true;
    const conciergePanel = el('section', 'cv-account-agent-concierge');
    conciergePanel.hidden = true;
    conciergePanel.setAttribute('aria-label', 'Concierge items needing attention');
    const messages = el('div', 'cv-account-agent-messages');
    messages.setAttribute('aria-live', 'polite');
    messages.setAttribute('aria-relevant', 'additions text');
    canvas.append(head, history, notice, conciergePanel, messages);

    const composerWrap = el('div', 'cv-account-agent-composer-wrap');
    const form = el('form', 'cv-account-agent-composer');
    const launch = el('button', 'cv-account-agent-launch', 'C');
    launch.type = 'button';
    launch.setAttribute('aria-label', 'Open Coveted Agent');
    const input = document.createElement('textarea');
    input.className = 'cv-account-agent-input';
    input.rows = 1;
    input.maxLength = 12000;
    input.placeholder = 'Message Coveted Agent…';
    input.setAttribute('aria-label', 'Message Coveted Agent');
    const send = el('button', 'cv-account-agent-send', '↑');
    send.type = 'submit';
    send.setAttribute('aria-label', 'Send message');
    form.append(launch, input, send);

    const meta = el('div', 'cv-account-agent-composer-meta');
    const status = el('span', '', 'Loading Agent…');
    const providerSelect = document.createElement('select');
    providerSelect.setAttribute('aria-label', 'Agent provider');
    meta.append(status, providerSelect);
    composerWrap.append(form, meta);
    root.append(canvas, composerWrap);
    document.body.appendChild(root);

    const state = {
        csrf: '',
        threadRef: '',
        userRef: '',
        providers: [],
        starters: [],
        welcomeTitle: 'How can I help?',
        welcomeBody: 'Ask about your Coveted events, invitations, benefits, hosting responsibilities, or account context.',
        ready: false,
        busy: false,
        actionBusy: false,
        isSystemAdmin: false,
    };

    const storageKey = 'coveted:account-agent-thread';

    const setOpen = (open) => {
        canvas.hidden = !open;
        launch.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) requestAnimationFrame(() => messages.scrollTop = messages.scrollHeight);
    };

    const clearMessages = () => {
        while (messages.firstChild) messages.removeChild(messages.firstChild);
    };

    const messageNode = (role, content, error = false) => {
        const item = el('article', `cv-account-agent-message is-${role}${error ? ' is-error' : ''}`);
        item.appendChild(el('span', 'cv-account-agent-message-label', role === 'user' ? 'You' : 'Coveted Agent'));
        const body = el('div', 'cv-account-agent-message-body');
        body.textContent = content;
        item.appendChild(body);
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
        return item;
    };

    const typingNode = () => {
        const item = el('article', 'cv-account-agent-message is-assistant');
        item.dataset.agentTyping = '1';
        item.appendChild(el('span', 'cv-account-agent-message-label', 'Coveted Agent'));
        const dots = el('div', 'cv-account-agent-typing');
        dots.append(el('i'), el('i'), el('i'));
        item.appendChild(dots);
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
        return item;
    };

    const renderEmpty = () => {
        clearMessages();
        const empty = el('div', 'cv-account-agent-empty');
        empty.appendChild(el('div', 'cv-account-agent-mark', 'C'));
        empty.appendChild(el('h3', '', state.welcomeTitle));
        empty.appendChild(el('p', '', state.welcomeBody));
        const starters = el('div', 'cv-account-agent-starters');
        state.starters.forEach((prompt) => {
            const button = el('button', '', prompt);
            button.type = 'button';
            button.addEventListener('click', () => {
                input.value = prompt;
                resizeInput();
                input.focus();
            });
            starters.appendChild(button);
        });
        empty.appendChild(starters);
        messages.appendChild(empty);
    };

    const renderMessages = (rows) => {
        clearMessages();
        if (!Array.isArray(rows) || rows.length === 0) {
            renderEmpty();
            return;
        }
        rows.forEach((row) => {
            if (row && (row.role === 'user' || row.role === 'assistant')) {
                messageNode(row.role, String(row.content || ''));
            }
        });
        messages.scrollTop = messages.scrollHeight;
    };

    const renderHistory = (rows) => {
        while (historySelect.firstChild) historySelect.removeChild(historySelect.firstChild);
        const first = document.createElement('option');
        first.value = '';
        first.textContent = 'Choose a conversation';
        historySelect.appendChild(first);
        (Array.isArray(rows) ? rows : []).forEach((row) => {
            const option = document.createElement('option');
            option.value = String(row.public_id || '');
            option.textContent = String(row.title || 'Chat');
            if (option.value && option.value === state.threadRef) option.selected = true;
            historySelect.appendChild(option);
        });
        history.hidden = historySelect.options.length <= 1;
    };

    const renderProviders = (providers) => {
        while (providerSelect.firstChild) providerSelect.removeChild(providerSelect.firstChild);
        state.providers = Array.isArray(providers) ? providers : [];
        state.providers.forEach((provider) => {
            const option = document.createElement('option');
            option.value = String(provider.provider || '');
            option.textContent = `${provider.label || provider.provider} · ${provider.model || ''}`;
            providerSelect.appendChild(option);
        });
    };

    const updateAvailability = () => {
        const enabled = state.ready && state.providers.length > 0 && !state.busy;
        input.disabled = !enabled;
        send.disabled = !enabled;
        providerSelect.disabled = !enabled || state.providers.length < 2;
        if (!state.ready) status.textContent = 'Chat storage required';
        else if (!state.providers.length) status.textContent = 'Chat provider required';
        else if (state.busy) status.textContent = 'Thinking…';
        else status.textContent = 'Ready · private Concierge';
    };

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
        });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) throw new Error('The Agent returned an unreadable response.');
        const data = await response.json();
        if (!response.ok || !data.ok) {
            const error = new Error(String(data.error || 'The Agent request failed.'));
            error.status = response.status;
            error.data = data;
            throw error;
        }
        return data;
    };

    const requestId = () => {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return `req_${window.crypto.randomUUID().replace(/[^A-Za-z0-9_-]/g, '')}`;
        }
        return `req_${Date.now()}_${Math.random().toString(36).slice(2, 14)}`;
    };

    const executeConciergeAction = async (action, confirmButton, cancelButton) => {
        if (state.actionBusy) return;
        const actionName = String(action.action || '');
        const targetRef = String(action.target_ref || '');
        if (!actionName || !targetRef) return;

        state.actionBusy = true;
        confirmButton.disabled = true;
        cancelButton.disabled = true;
        confirmButton.textContent = 'Working…';

        const body = new URLSearchParams();
        body.set('csrf_token', state.csrf);
        body.set('confirmed', '1');
        body.set('action', actionName);
        body.set('target_ref', targetRef);
        body.set('decision', String(action.decision || ''));
        body.set('guest_count', String(Number.isInteger(action.guest_count) ? action.guest_count : 0));
        body.set('thread_ref', state.threadRef);
        body.set('request_id', requestId());

        try {
            const data = await requestJson('/api/account-agent-action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString(),
            });
            if (data.thread && data.thread.public_id) {
                state.threadRef = String(data.thread.public_id);
                localStorage.setItem(storageKey, state.threadRef);
            }
            await loadBootstrap(state.threadRef);
            if (!data.thread || !data.thread.public_id) {
                notice.hidden = false;
                notice.textContent = `${String(data.message || 'Action completed.')} Chat history could not be saved for this action.`;
            }
            setOpen(true);
        } catch (error) {
            messageNode('assistant', error instanceof Error ? error.message : 'The Concierge action failed.', true);
        } finally {
            state.actionBusy = false;
        }
    };

    const renderConcierge = (concierge) => {
        while (conciergePanel.firstChild) conciergePanel.removeChild(conciergePanel.firstChild);
        const items = concierge && Array.isArray(concierge.attention) ? concierge.attention : [];
        if (!items.length || state.isSystemAdmin) {
            conciergePanel.hidden = true;
            return;
        }

        const heading = el('div', 'cv-account-agent-concierge-head');
        heading.appendChild(el('span', 'cv-account-agent-message-label', 'Needs attention'));
        heading.appendChild(el('strong', '', `${items.length} item${items.length === 1 ? '' : 's'}`));
        conciergePanel.appendChild(heading);

        items.forEach((item) => {
            const card = el('article', 'cv-account-agent-concierge-card');
            const copy = el('div', 'cv-account-agent-concierge-copy');
            copy.appendChild(el('strong', '', String(item.title || 'Coveted update')));
            const detail = String(item.detail || '').trim();
            if (detail) copy.appendChild(el('p', '', detail));
            card.appendChild(copy);

            const actions = el('div', 'cv-account-agent-concierge-actions');
            (Array.isArray(item.actions) ? item.actions : []).forEach((action) => {
                const button = el('button', 'cv-account-agent-concierge-action', String(action.label || 'Review'));
                button.type = 'button';
                button.addEventListener('click', () => {
                    if (state.actionBusy || actions.querySelector('[data-concierge-confirm]')) return;
                    const confirm = el('div', 'cv-account-agent-concierge-confirm');
                    confirm.dataset.conciergeConfirm = '1';
                    confirm.appendChild(el('span', '', `Confirm “${String(action.label || 'this action')}”?`));
                    const confirmButton = el('button', 'cv-account-agent-concierge-confirm-button', String(action.confirm_label || 'Confirm'));
                    confirmButton.type = 'button';
                    const cancelButton = el('button', 'cv-account-agent-concierge-cancel', 'Cancel');
                    cancelButton.type = 'button';
                    confirmButton.addEventListener('click', () => executeConciergeAction(action, confirmButton, cancelButton));
                    cancelButton.addEventListener('click', () => confirm.remove());
                    confirm.append(confirmButton, cancelButton);
                    actions.appendChild(confirm);
                });
                actions.appendChild(button);
            });

            const href = safeInternalPath(item.url);
            if (href) {
                const open = el('a', 'cv-account-agent-concierge-link', 'Open');
                open.href = href;
                actions.appendChild(open);
            }
            card.appendChild(actions);
            conciergePanel.appendChild(card);
        });
        conciergePanel.hidden = false;
    };

    const applyBootstrap = (data) => {
        state.csrf = String(data.csrf || '');
        state.userRef = String(data.user_ref || '');
        state.ready = Boolean(data.storage_ready);
        state.isSystemAdmin = Boolean(data.is_system_admin);
        state.starters = Array.isArray(data.starters) ? data.starters.map(String) : [];
        const welcome = data.welcome && typeof data.welcome === 'object' ? data.welcome : {};
        state.welcomeTitle = String(welcome.title || 'How can I help?');
        state.welcomeBody = String(welcome.body || 'Ask about your Coveted events, invitations, benefits, hosting responsibilities, or account context.');
        roleSmall.textContent = `${data.role || 'Member'} · private account context`;
        renderProviders(data.providers);
        state.threadRef = data.thread ? String(data.thread.public_id || '') : '';
        titleStrong.textContent = data.thread ? String(data.thread.title || 'Coveted Agent') : 'Coveted Agent';
        if (state.threadRef) localStorage.setItem(storageKey, state.threadRef);
        else localStorage.removeItem(storageKey);
        renderHistory(data.recent_threads);
        renderConcierge(data.concierge);
        renderMessages(data.messages);

        notice.hidden = true;
        notice.textContent = '';
        if (!state.ready) {
            notice.hidden = false;
            notice.textContent = String(data.storage_error || 'Persistent Agent chat storage is unavailable.');
        } else if (!state.providers.length) {
            notice.hidden = false;
            notice.textContent = state.isSystemAdmin
                ? 'Agent chat needs an enabled OpenAI or Anthropic provider. Configure it in Admin → AI Settings.'
                : 'Agent chat is not configured yet. RSVP confirmation cards remain available, but a System Admin must enable a provider for conversation.';
        }
        updateAvailability();
    };

    const loadBootstrap = async (threadRef = '') => {
        status.textContent = 'Loading Agent…';
        try {
            const ref = threadRef || localStorage.getItem(storageKey) || '';
            const query = ref ? `?thread_ref=${encodeURIComponent(ref)}` : '';
            const data = await requestJson(`/api/account-agent-bootstrap.php${query}`);
            applyBootstrap(data);
        } catch (error) {
            state.ready = false;
            updateAvailability();
            notice.hidden = false;
            notice.textContent = error instanceof Error ? error.message : 'The Agent is unavailable.';
        }
    };

    const resizeInput = () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
    };

    const submitMessage = async () => {
        const text = input.value.trim();
        if (!text || state.busy || !state.ready || !state.providers.length) return;
        const provider = providerSelect.value || String(state.providers[0].provider || '');
        const currentRequest = requestId();
        input.value = '';
        resizeInput();
        setOpen(true);
        const empty = messages.querySelector('.cv-account-agent-empty');
        if (empty) empty.remove();
        messageNode('user', text);
        const typing = typingNode();
        state.busy = true;
        updateAvailability();

        const body = new URLSearchParams();
        body.set('csrf_token', state.csrf);
        body.set('message', text);
        body.set('provider', provider);
        body.set('request_id', currentRequest);
        body.set('thread_ref', state.threadRef);
        body.set('surface', window.location.pathname);

        try {
            const data = await requestJson('/api/account-agent-chat.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString(),
            });
            typing.remove();
            if (data.thread && data.thread.public_id) {
                state.threadRef = String(data.thread.public_id);
                localStorage.setItem(storageKey, state.threadRef);
                titleStrong.textContent = String(data.thread.title || 'Coveted Agent');
            }
            messageNode('assistant', String(data.text || ''));
            await loadBootstrap(state.threadRef);
            setOpen(true);
        } catch (error) {
            typing.remove();
            const data = error && error.data ? error.data : null;
            if (data && data.thread && data.thread.public_id) {
                state.threadRef = String(data.thread.public_id);
                localStorage.setItem(storageKey, state.threadRef);
            }
            messageNode('assistant', error instanceof Error ? error.message : 'The Agent request failed.', true);
        } finally {
            state.busy = false;
            updateAvailability();
            input.focus();
        }
    };

    launch.addEventListener('click', () => setOpen(canvas.hidden));
    closeButton.addEventListener('click', () => setOpen(false));
    newButton.addEventListener('click', () => {
        state.threadRef = '';
        localStorage.removeItem(storageKey);
        titleStrong.textContent = 'Coveted Agent';
        historySelect.value = '';
        renderEmpty();
        setOpen(true);
        input.focus();
    });
    historySelect.addEventListener('change', () => {
        const ref = historySelect.value;
        if (ref) loadBootstrap(ref).then(() => setOpen(true));
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submitMessage();
    });
    input.addEventListener('input', resizeInput);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            submitMessage();
        }
    });

    const syncViewport = () => {
        const viewport = window.visualViewport;
        if (!viewport) {
            document.documentElement.style.setProperty('--cv-agent-keyboard-offset', '0px');
            return;
        }
        const offset = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
        document.documentElement.style.setProperty('--cv-agent-keyboard-offset', `${Math.round(offset)}px`);
    };
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncViewport);
        window.visualViewport.addEventListener('scroll', syncViewport);
    }
    syncViewport();
    resizeInput();
    loadBootstrap();
})();
