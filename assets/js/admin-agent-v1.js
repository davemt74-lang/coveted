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
    const threadTitle = root.querySelector('[data-agent-thread-title]');
    const historyToggle = root.querySelector('[data-agent-history-toggle]');
    const historyPanel = root.querySelector('[data-agent-history-panel]');
    const historyClose = root.querySelector('[data-agent-history-close]');
    const threadSearch = root.querySelector('[data-agent-thread-search]');
    const threadResults = root.querySelector('[data-agent-thread-results]');
    const renameThreadButton = root.querySelector('[data-agent-rename-thread]');
    const archiveThreadButton = root.querySelector('[data-agent-archive-thread]');

    const endpoint = root.dataset.endpoint || '/api/admin-agent-chat.php';
    const threadsEndpoint = root.dataset.threadsEndpoint || '/api/admin-agent-threads.php';
    const activityEndpoint = root.dataset.activityEndpoint || '';
    const operationsActivityEndpoint = root.dataset.operationsActivityEndpoint || '';
    const csrf = root.dataset.csrf || '';
    const autonomousActions = root.dataset.autonomousActions === '1';
    const threadStorageReady = root.dataset.threadStorageReady === '1';

    const activityStorageKey = 'coveted.adminAgent.liveActivity.v2';
    const crmCursorKey = 'coveted.adminAgent.crmCursor.v1';
    const auditCursorKey = 'coveted.adminAgent.auditCursor.v1';
    const pendingRequestKey = 'coveted.adminAgent.pendingRequest.v1';
    const maxEphemeralMessages = 80;

    const initialCrmCursor = Math.max(0, Number.parseInt(root.dataset.crmCursor || '0', 10) || 0);
    const initialAuditCursor = Math.max(0, Number.parseInt(root.dataset.auditCursor || '0', 10) || 0);

    let busy = false;
    let currentThreadRef = root.dataset.currentThread || '';
    let currentThreadTitle = threadTitle?.textContent?.trim() || 'New Chat';
    let conversationMessages = [];
    let activityMessages = [];
    let crmCursor = initialCrmCursor;
    let auditCursor = initialAuditCursor;
    let crmPolling = false;
    let operationsPolling = false;
    let crmPollingEnabled = activityEndpoint !== '';
    let operationsPollingEnabled = operationsActivityEndpoint !== '';
    let activityNoticeTimer = 0;
    let searchTimer = 0;

    const defaultStatus = () => {
        if (!threadStorageReady) return 'Chat storage required';
        if (provider?.disabled) return 'Provider required';
        return autonomousActions ? 'Ready · Autonomous actions ON' : 'Ready · Read/advise mode';
    };

    const safeInternalHref = (value, fallback = '/admin/operations.php') => {
        const href = typeof value === 'string' ? value.trim() : '';
        return href.startsWith('/') && !href.startsWith('//') ? href : fallback;
    };

    const parseStoredJson = (key, fallback) => {
        try {
            return JSON.parse(sessionStorage.getItem(key) || 'null') ?? fallback;
        } catch (_) {
            return fallback;
        }
    };

    const loadEphemeralState = () => {
        const stored = parseStoredJson(activityStorageKey, []);
        if (Array.isArray(stored)) {
            activityMessages = stored
                .filter((entry) => entry
                    && ['activity', 'ops'].includes(entry.role)
                    && typeof entry.content === 'string')
                .slice(-maxEphemeralMessages);
        }

        try {
            const savedCursor = Number.parseInt(sessionStorage.getItem(crmCursorKey) || '', 10);
            if (Number.isInteger(savedCursor) && savedCursor >= 0) crmCursor = savedCursor;
        } catch (_) {
            crmCursor = initialCrmCursor;
        }

        try {
            const savedCursor = Number.parseInt(sessionStorage.getItem(auditCursorKey) || '', 10);
            if (Number.isInteger(savedCursor) && savedCursor >= 0) auditCursor = savedCursor;
        } catch (_) {
            auditCursor = initialAuditCursor;
        }
    };

    const saveEphemeralState = () => {
        try {
            sessionStorage.setItem(activityStorageKey, JSON.stringify(activityMessages.slice(-maxEphemeralMessages)));
        } catch (_) {}
    };

    const saveCrmCursor = () => {
        try { sessionStorage.setItem(crmCursorKey, String(crmCursor)); } catch (_) {}
    };

    const saveAuditCursor = () => {
        try { sessionStorage.setItem(auditCursorKey, String(auditCursor)); } catch (_) {}
    };

    const getPendingRequest = () => {
        const value = parseStoredJson(pendingRequestKey, null);
        return value && typeof value === 'object' ? value : null;
    };

    const setPendingRequest = (value) => {
        try {
            if (value) sessionStorage.setItem(pendingRequestKey, JSON.stringify(value));
            else sessionStorage.removeItem(pendingRequestKey);
        } catch (_) {}
    };

    const requestId = () => {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return `req_${window.crypto.randomUUID()}`;
        }
        const random = Math.random().toString(36).slice(2);
        return `req_${Date.now().toString(36)}_${random}`;
    };

    const scrollToLatest = () => {
        requestAnimationFrame(() => {
            const last = canvas?.querySelector('.cv-admin-agent-message:last-child');
            if (last) last.scrollIntoView({ behavior: 'smooth', block: 'end' });
        });
    };

    const makeMessage = (entry) => {
        const article = document.createElement('article');
        article.className = `cv-admin-agent-message is-${entry.role}`;
        if (entry.role === 'action' && entry.ok === false) article.classList.add('is-failed');

        const label = document.createElement('span');
        label.className = 'cv-admin-agent-message-label';
        if (entry.role === 'user') label.textContent = 'YOU';
        else if (entry.role === 'activity') label.textContent = 'CRM';
        else if (entry.role === 'ops') label.textContent = 'ACTIVITY';
        else if (entry.role === 'action') label.textContent = 'ACTION';
        else label.textContent = 'COVETED';

        const body = document.createElement('div');
        body.className = 'cv-admin-agent-message-body';

        if (entry.role === 'activity') {
            const title = entry.kind === 'newsletter' ? 'New newsletter signup' : 'New CRM submission';
            const identity = [entry.name, entry.city].filter(Boolean).join(' · ');
            const interests = Array.isArray(entry.interests) ? entry.interests.filter(Boolean).join(' · ') : '';
            body.textContent = [title, identity, interests ? `Interested in: ${interests}` : ''].filter(Boolean).join('\n');
            const link = document.createElement('a');
            link.href = safeInternalHref(entry.href, '/admin/crm.php?status=new');
            link.textContent = 'Review in CRM →';
            body.append(document.createTextNode('\n'), link);
        } else if (entry.role === 'ops') {
            body.textContent = [entry.title || 'Coveted activity', entry.detail || ''].filter(Boolean).join('\n');
            const link = document.createElement('a');
            link.href = safeInternalHref(entry.href);
            link.textContent = 'Open workspace →';
            body.append(document.createTextNode('\n'), link);
        } else if (entry.role === 'action') {
            const title = entry.label || entry.action || 'Admin action';
            body.textContent = `${title}\n${entry.ok === false ? 'Failed' : 'Completed'}: ${entry.content}`;
            if (entry.entityRef) body.append(document.createTextNode(`\nReference: ${entry.entityRef}`));
        } else {
            body.textContent = entry.content;
        }

        article.append(label, body);

        if (entry.role === 'assistant' && entry.meta) {
            const meta = document.createElement('small');
            meta.textContent = entry.meta;
            article.append(meta);
        } else if (entry.role === 'activity' && entry.submittedAt) {
            const meta = document.createElement('small');
            meta.textContent = `Received ${entry.submittedAt}`;
            article.append(meta);
        } else if (entry.role === 'ops') {
            const meta = document.createElement('small');
            meta.textContent = [entry.category || 'Activity', entry.occurredAt ? `Recorded ${entry.occurredAt}` : '']
                .filter(Boolean).join(' · ');
            article.append(meta);
        } else if (entry.role === 'action') {
            const meta = document.createElement('small');
            meta.textContent = 'Durable canonical Admin action result';
            article.append(meta);
        }

        return article;
    };

    const render = (shouldScroll = true) => {
        if (!canvas || !empty) return;
        canvas.querySelectorAll('.cv-admin-agent-message').forEach((node) => node.remove());
        const hasConversation = conversationMessages.some((entry) => ['user', 'assistant', 'action'].includes(entry.role));
        empty.hidden = hasConversation;
        conversationMessages.forEach((entry) => canvas.appendChild(makeMessage(entry)));
        activityMessages.forEach((entry) => canvas.appendChild(makeMessage(entry)));
        if ((conversationMessages.length || activityMessages.length) && shouldScroll) scrollToLatest();
    };

    const setBusy = (value, message = '') => {
        busy = value;
        if (input) input.disabled = value || !threadStorageReady || !provider || provider.disabled;
        const submit = form?.querySelector('[type="submit"]');
        if (submit) submit.disabled = value || !threadStorageReady || !provider || provider.disabled;
        if (status) status.textContent = message || (value ? 'Thinking…' : defaultStatus());
        root.classList.toggle('is-thinking', value);
    };

    const resizeInput = () => {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = `${Math.min(Math.max(input.scrollHeight, 24), 160)}px`;
    };

    const setThreadUi = (ref, title, pushUrl = false) => {
        currentThreadRef = ref || '';
        currentThreadTitle = title || 'New Chat';
        root.dataset.currentThread = currentThreadRef;
        if (threadTitle) threadTitle.textContent = currentThreadTitle;
        if (renameThreadButton) renameThreadButton.hidden = currentThreadRef === '';
        if (archiveThreadButton) archiveThreadButton.hidden = currentThreadRef === '';
        if (pushUrl) {
            const target = currentThreadRef
                ? `/admin/agent.php?thread=${encodeURIComponent(currentThreadRef)}`
                : '/admin/agent.php?new=1';
            window.history.replaceState({}, '', target);
        }
    };

    const normalizeServerMessages = (rows) => {
        if (!Array.isArray(rows)) return [];
        return rows.map((row) => {
            const role = ['user', 'assistant', 'action'].includes(row?.role) ? row.role : '';
            if (!role) return null;
            if (role === 'action') {
                return {
                    role,
                    content: typeof row?.content === 'string' ? row.content : 'Action processed.',
                    action: typeof row?.action === 'string' ? row.action : '',
                    label: typeof row?.label === 'string' ? row.label : 'Admin action',
                    ok: row?.ok === true,
                    entityRef: typeof row?.entity_ref === 'string' ? row.entity_ref : '',
                    requestId: typeof row?.request_id === 'string' ? row.request_id : '',
                };
            }
            const providerLabel = row?.provider === 'openai' ? 'ChatGPT' : (row?.provider === 'anthropic' ? 'Claude' : '');
            return {
                role,
                content: typeof row?.content === 'string' ? row.content : '',
                meta: role === 'assistant' ? [providerLabel, row?.model].filter(Boolean).join(' · ') : '',
                requestId: typeof row?.request_id === 'string' ? row.request_id : '',
            };
        }).filter(Boolean);
    };

    const loadThread = async (ref, pushUrl = false) => {
        if (!ref || !threadStorageReady) {
            conversationMessages = [];
            setThreadUi('', 'New Chat', pushUrl);
            render(false);
            return;
        }

        const url = new URL(threadsEndpoint, window.location.origin);
        url.searchParams.set('thread', ref);
        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.ok !== true) {
            throw new Error(data?.error || `Unable to load chat (${response.status}).`);
        }
        conversationMessages = normalizeServerMessages(data.messages);
        setThreadUi(String(data.thread?.public_id || ref), String(data.thread?.title || 'New Chat'), pushUrl);
        render();
    };

    const postThreadAction = async (action, values = {}) => {
        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        body.set('action', action);
        Object.entries(values).forEach(([key, value]) => body.set(key, String(value ?? '')));
        const response = await fetch(threadsEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            credentials: 'same-origin',
            cache: 'no-store',
            body: body.toString(),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.ok !== true) {
            throw new Error(data?.error || `Chat history request failed (${response.status}).`);
        }
        return data;
    };

    const ensureThread = async () => {
        if (currentThreadRef) return currentThreadRef;
        const data = await postThreadAction('create');
        const ref = String(data.thread?.public_id || '');
        if (!ref) throw new Error('The new Admin Agent chat could not be created.');
        setThreadUi(ref, String(data.thread?.title || 'New Chat'), true);
        conversationMessages = [];
        render(false);
        return ref;
    };

    const renderThreadResults = (threads) => {
        if (!threadResults) return;
        threadResults.textContent = '';
        if (!Array.isArray(threads) || threads.length === 0) {
            const emptyResult = document.createElement('p');
            emptyResult.className = 'cv-form-help';
            emptyResult.textContent = 'No matching Admin Agent chats.';
            threadResults.appendChild(emptyResult);
            return;
        }

        threads.forEach((thread) => {
            const ref = String(thread?.public_id || '');
            if (!ref) return;
            const link = document.createElement('a');
            link.className = 'cv-admin-agent-history-row';
            link.href = `/admin/agent.php?thread=${encodeURIComponent(ref)}`;

            const copy = document.createElement('span');
            const title = document.createElement('strong');
            title.textContent = String(thread?.title || 'New Chat');
            const meta = document.createElement('small');
            const count = Number.parseInt(thread?.message_count, 10) || 0;
            meta.textContent = `${count} saved message${count === 1 ? '' : 's'}${thread?.last_message_at ? ` · ${thread.last_message_at}` : ''}`;
            copy.append(title, meta);

            const arrow = document.createElement('span');
            arrow.textContent = '→';
            link.append(copy, arrow);
            threadResults.appendChild(link);
        });
    };

    const searchThreads = async (query = '') => {
        if (!threadStorageReady || !threadResults) return;
        const url = new URL(threadsEndpoint, window.location.origin);
        if (query.trim()) url.searchParams.set('q', query.trim());
        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.ok !== true) {
            throw new Error(data?.error || 'Unable to search chats.');
        }
        renderThreadResults(data.threads);
    };

    const announceActivity = (count, kind = 'CRM') => {
        if (!status || busy || count < 1) return;
        window.clearTimeout(activityNoticeTimer);
        status.textContent = kind === 'operations'
            ? (count === 1 ? '1 new operational update' : `${count} new operational updates`)
            : (count === 1 ? '1 new CRM submission' : `${count} new CRM submissions`);
        activityNoticeTimer = window.setTimeout(() => {
            if (!busy && status) status.textContent = defaultStatus();
        }, 8000);
    };

    const appendCrmItems = (items) => {
        if (!Array.isArray(items) || items.length === 0) return 0;
        const seen = new Set(activityMessages
            .filter((entry) => entry.role === 'activity' && typeof entry.activityKey === 'string')
            .map((entry) => entry.activityKey));
        let added = 0;
        items.forEach((item) => {
            const id = Number.parseInt(item?.id, 10);
            if (!Number.isInteger(id) || id < 1) return;
            const activityKey = `crm:${id}`;
            if (seen.has(activityKey)) return;
            activityMessages.push({
                role: 'activity',
                content: item?.kind === 'newsletter' ? 'New newsletter signup' : 'New CRM submission',
                activityKey,
                kind: item?.kind === 'newsletter' ? 'newsletter' : 'invite',
                name: typeof item?.name === 'string' ? item.name : '',
                city: typeof item?.city === 'string' ? item.city : '',
                interests: Array.isArray(item?.interests) ? item.interests.slice(0, 8).map(String) : [],
                submittedAt: typeof item?.submitted_at === 'string' ? item.submitted_at : '',
                href: safeInternalHref(item?.href, '/admin/crm.php?status=new'),
            });
            seen.add(activityKey);
            added += 1;
        });
        activityMessages = activityMessages.slice(-maxEphemeralMessages);
        return added;
    };

    const appendOperationalItems = (items) => {
        if (!Array.isArray(items) || items.length === 0) return 0;
        const seen = new Set(activityMessages
            .filter((entry) => entry.role === 'ops' && typeof entry.activityKey === 'string')
            .map((entry) => entry.activityKey));
        let added = 0;
        items.forEach((item) => {
            const id = Number.parseInt(item?.id, 10);
            if (!Number.isInteger(id) || id < 1) return;
            const activityKey = `audit:${id}`;
            if (seen.has(activityKey)) return;
            activityMessages.push({
                role: 'ops',
                content: typeof item?.title === 'string' ? item.title : 'Coveted activity',
                activityKey,
                category: typeof item?.category === 'string' ? item.category : 'Activity',
                title: typeof item?.title === 'string' ? item.title : 'Coveted activity',
                detail: typeof item?.detail === 'string' ? item.detail : '',
                occurredAt: typeof item?.occurred_at === 'string' ? item.occurred_at : '',
                href: safeInternalHref(item?.href),
            });
            seen.add(activityKey);
            added += 1;
        });
        activityMessages = activityMessages.slice(-maxEphemeralMessages);
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
            if (!response.ok || !data || data.ok !== true || data.available === false) return;
            const nextCursor = Number.parseInt(data.cursor, 10);
            if (Number.isInteger(nextCursor) && nextCursor >= 0) {
                crmCursor = nextCursor;
                saveCrmCursor();
            }
            const added = appendCrmItems(data.items);
            if (added > 0) {
                saveEphemeralState();
                render(false);
                announceActivity(added, 'CRM');
            }
            continueSoon = data.has_more === true;
        } catch (_) {
            // Fail soft: live CRM visibility must never interrupt chat.
        } finally {
            crmPolling = false;
        }
        if (continueSoon && crmPollingEnabled && !document.hidden) window.setTimeout(pollCrm, 400);
    };

    const pollOperations = async () => {
        if (!operationsPollingEnabled || operationsPolling || document.hidden) return;
        operationsPolling = true;
        let continueSoon = false;
        try {
            const url = new URL(operationsActivityEndpoint, window.location.origin);
            url.searchParams.set('cursor', String(auditCursor));
            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (response.status === 401 || response.status === 403) {
                operationsPollingEnabled = false;
                return;
            }
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || data.ok !== true || data.available === false) return;
            const nextCursor = Number.parseInt(data.cursor, 10);
            if (Number.isInteger(nextCursor) && nextCursor >= 0) {
                auditCursor = nextCursor;
                saveAuditCursor();
            }
            const added = appendOperationalItems(data.items);
            if (added > 0) {
                saveEphemeralState();
                render(false);
                announceActivity(added, 'operations');
            }
            continueSoon = data.has_more === true;
        } catch (_) {
            // Fail soft: operational polling must never interrupt chat.
        } finally {
            operationsPolling = false;
        }
        if (continueSoon && operationsPollingEnabled && !document.hidden) window.setTimeout(pollOperations, 400);
    };

    const send = async (text) => {
        if (busy || !threadStorageReady || !provider || provider.disabled) return;
        const trimmed = String(text || '').trim();
        if (!trimmed) return;

        let threadRef = currentThreadRef;
        try {
            setBusy(true, 'Creating durable chat…');
            threadRef = await ensureThread();
        } catch (error) {
            setBusy(false, 'Chat creation failed');
            if (status) status.textContent = error instanceof Error ? error.message : 'Unable to create the chat.';
            return;
        }

        const pending = getPendingRequest();
        const id = pending
            && pending.threadRef === threadRef
            && pending.message === trimmed
            && pending.provider === provider.value
            && typeof pending.requestId === 'string'
            ? pending.requestId
            : requestId();

        setPendingRequest({ threadRef, requestId: id, message: trimmed, provider: provider.value });
        conversationMessages.push({ role: 'user', content: trimmed, pending: true, requestId: id });
        render();
        if (input) input.value = '';
        resizeInput();
        setBusy(true, autonomousActions ? 'Thinking · durable autonomous tools available…' : 'Thinking…');

        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        body.set('provider', provider.value);
        body.set('message', trimmed);
        body.set('thread_ref', threadRef);
        body.set('request_id', id);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                credentials: 'same-origin',
                cache: 'no-store',
                body: body.toString(),
            });
            const data = await response.json().catch(() => null);

            if (response.status === 409) {
                throw new Error(data?.error || 'This durable request is still processing. Retry the same message to resume without duplicate actions.');
            }
            if (!response.ok || !data || data.ok !== true) {
                throw new Error(data?.error || `Request failed (${response.status}).`);
            }

            setPendingRequest(null);
            const returnedRef = String(data.thread?.public_id || threadRef);
            const returnedTitle = String(data.thread?.title || currentThreadTitle || 'New Chat');
            setThreadUi(returnedRef, returnedTitle, true);
            await loadThread(returnedRef, false);
            setBusy(false, defaultStatus());
            input?.focus();
        } catch (error) {
            conversationMessages = conversationMessages.filter((entry) => !(entry.pending && entry.requestId === id));
            render(false);
            if (input) {
                input.value = trimmed;
                resizeInput();
            }
            setBusy(false, 'Request interrupted · safe to retry');
            const article = document.createElement('article');
            article.className = 'cv-admin-agent-error';
            article.textContent = error instanceof Error ? error.message : 'The Admin Agent request failed.';
            canvas?.appendChild(article);
            scrollToLatest();
        }
    };

    loadEphemeralState();
    render(false);
    resizeInput();
    setBusy(false, defaultStatus());

    if (currentThreadRef && threadStorageReady) {
        loadThread(currentThreadRef, false).catch((error) => {
            setThreadUi('', 'New Chat', false);
            conversationMessages = [];
            render(false);
            if (status) status.textContent = error instanceof Error ? error.message : 'Unable to load saved chat.';
        });
    }

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

    historyToggle?.addEventListener('click', () => {
        if (!historyPanel) return;
        historyPanel.hidden = !historyPanel.hidden;
        if (!historyPanel.hidden) {
            searchThreads(threadSearch?.value || '').catch(() => renderThreadResults([]));
            threadSearch?.focus();
        }
    });

    historyClose?.addEventListener('click', () => {
        if (historyPanel) historyPanel.hidden = true;
    });

    threadSearch?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            searchThreads(threadSearch.value).catch(() => renderThreadResults([]));
        }, 250);
    });

    renameThreadButton?.addEventListener('click', async () => {
        if (!currentThreadRef || busy) return;
        const nextTitle = window.prompt('Rename this Admin Agent chat:', currentThreadTitle);
        if (nextTitle === null) return;
        const trimmed = nextTitle.trim();
        if (!trimmed || trimmed === currentThreadTitle) return;
        try {
            setBusy(true, 'Renaming chat…');
            await postThreadAction('rename', { thread: currentThreadRef, title: trimmed });
            setThreadUi(currentThreadRef, trimmed, false);
            setBusy(false, defaultStatus());
        } catch (error) {
            setBusy(false, error instanceof Error ? error.message : 'Rename failed');
        }
    });

    archiveThreadButton?.addEventListener('click', async () => {
        if (!currentThreadRef || busy || !window.confirm('Archive this Admin Agent chat?')) return;
        try {
            setBusy(true, 'Archiving chat…');
            await postThreadAction('archive', { thread: currentThreadRef });
            setPendingRequest(null);
            conversationMessages = [];
            setThreadUi('', 'New Chat', true);
            render(false);
            setBusy(false, defaultStatus());
        } catch (error) {
            setBusy(false, error instanceof Error ? error.message : 'Archive failed');
        }
    });

    if (crmPollingEnabled) {
        window.setTimeout(pollCrm, 1500);
        window.setInterval(pollCrm, 60000);
    }
    if (operationsPollingEnabled) {
        window.setTimeout(pollOperations, 2200);
        window.setInterval(pollOperations, 60000);
    }
    if (crmPollingEnabled || operationsPollingEnabled) {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                pollCrm();
                pollOperations();
            }
        });
    }
})();