(() => {
    'use strict';

    if (!document.querySelector('link[rel="manifest"]')) {
        const manifest = document.createElement('link');
        manifest.rel = 'manifest';
        manifest.href = '/manifest.webmanifest';
        document.head.appendChild(manifest);
    }

    if (!document.querySelector('link[rel="apple-touch-icon"]')) {
        const appleIcon = document.createElement('link');
        appleIcon.rel = 'apple-touch-icon';
        appleIcon.href = '/uploads/pwa/apple-touch-icon.png';
        document.head.appendChild(appleIcon);
    }

    const splashLinks = [
        { href: '/uploads/pwa/splash-portrait.png', media: '(orientation: portrait)' },
        { href: '/uploads/pwa/splash-landscape.png', media: '(orientation: landscape)' }
    ];

    splashLinks.forEach((splash) => {
        const exists = Array.from(document.querySelectorAll('link[rel="apple-touch-startup-image"]'))
            .some((link) => link.getAttribute('href') === splash.href);
        if (exists) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'apple-touch-startup-image';
        link.href = splash.href;
        link.media = splash.media;
        document.head.appendChild(link);
    });

    const serviceWorkerReady = 'serviceWorker' in navigator && window.isSecureContext
        ? navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(() => navigator.serviceWorker.ready)
            .catch((error) => {
                console.error('Coveted service worker registration failed:', error);
                return null;
            })
        : Promise.resolve(null);

    const randomClientId = () => {
        if (window.crypto?.randomUUID) {
            return `pwa_${window.crypto.randomUUID().replaceAll('-', '')}`;
        }
        const bytes = new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
        return `pwa_${Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')}`;
    };

    const notificationClientId = (() => {
        try {
            const key = 'coveted_notification_client_id';
            let clientId = window.localStorage.getItem(key) || '';
            if (!/^[A-Za-z0-9_-]{16,80}$/.test(clientId)) {
                clientId = randomClientId();
                window.localStorage.setItem(key, clientId);
            }
            return clientId;
        } catch {
            return randomClientId();
        }
    })();

    const logoutForm = document.querySelector('form[action="/auth.php?action=logout"]');
    if (logoutForm) {
        let clientField = logoutForm.querySelector('input[name="notification_client_id"]');
        if (!clientField) {
            clientField = document.createElement('input');
            clientField.type = 'hidden';
            clientField.name = 'notification_client_id';
            logoutForm.appendChild(clientField);
        }
        clientField.value = notificationClientId;
    }

    let notificationState = null;

    const base64UrlToUint8Array = (value) => {
        const padding = '='.repeat((4 - (value.length % 4)) % 4);
        const normalized = (value + padding).replaceAll('-', '+').replaceAll('_', '/');
        const raw = window.atob(normalized);
        return Uint8Array.from(raw, (character) => character.charCodeAt(0));
    };

    const postPushState = async (fields) => {
        if (!notificationState?.csrf_token) {
            throw new Error('Notification session state is unavailable.');
        }

        const body = new URLSearchParams({
            csrf_token: notificationState.csrf_token,
            client_id: notificationClientId,
            ...fields
        });
        const response = await fetch('/push-subscription.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Unable to update push notifications.');
        }
        return data;
    };

    const registerPushSubscription = async (askPermission) => {
        if (!notificationState?.push?.enabled || !('Notification' in window) || !('PushManager' in window)) {
            throw new Error('Web Push is not available on this device.');
        }

        let permission = Notification.permission;
        if (permission === 'default' && askPermission) {
            permission = await Notification.requestPermission();
        }
        if (permission !== 'granted') {
            throw new Error(permission === 'denied'
                ? 'Browser notifications are blocked for Coveted.'
                : 'Notification permission was not granted.');
        }

        const registration = await serviceWorkerReady;
        if (!registration) {
            throw new Error('The Coveted service worker is unavailable.');
        }

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8Array(notificationState.push.public_key)
            });
        }

        await postPushState({
            action: 'subscribe',
            subscription: JSON.stringify(subscription.toJSON())
        });
        return subscription;
    };

    const disablePushSubscription = async () => {
        const registration = await serviceWorkerReady;
        const subscription = registration ? await registration.pushManager.getSubscription() : null;
        await postPushState({ action: 'disable' });
        if (subscription) {
            await subscription.unsubscribe();
        }
    };

    const renderNotificationBell = (unreadCount) => {
        const actions = document.querySelector('.cv-header-actions');
        if (!actions) {
            return;
        }

        let bell = actions.querySelector('[data-notification-bell]');
        if (!bell) {
            bell = document.createElement('a');
            bell.href = '/notifications.php';
            bell.className = 'cv-button cv-button-soft cv-notification-bell';
            bell.dataset.notificationBell = '';
            bell.setAttribute('aria-label', 'Notifications');

            const icon = document.createElement('span');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '●';
            bell.appendChild(icon);

            const count = document.createElement('span');
            count.className = 'cv-status';
            count.dataset.notificationCount = '';
            count.hidden = true;
            bell.appendChild(count);
            actions.prepend(bell);
        }

        const count = bell.querySelector('[data-notification-count]');
        if (!count) {
            return;
        }
        const value = Math.max(0, Number(unreadCount) || 0);
        count.textContent = value > 99 ? '99+' : String(value);
        count.hidden = value < 1;
        bell.setAttribute('aria-label', value > 0 ? `Notifications, ${value} unread` : 'Notifications');
    };

    const setPushStatus = (message, isError = false) => {
        const status = document.querySelector('[data-push-status]');
        if (!status) {
            return;
        }
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    };

    const loadNotificationState = async () => {
        try {
            const response = await fetch(`/push-subscription.php?client_id=${encodeURIComponent(notificationClientId)}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
                return null;
            }
            const state = await response.json();
            if (!state.ok) {
                return null;
            }

            notificationState = state;
            renderNotificationBell(state.unread_count);

            if (state.push?.enabled && 'Notification' in window && Notification.permission === 'granted') {
                try {
                    await registerPushSubscription(false);
                    setPushStatus('Enabled on this device');
                } catch (error) {
                    console.error('Coveted push subscription sync failed:', error);
                }
            }
            return state;
        } catch (error) {
            console.error('Coveted notification state failed:', error);
            return null;
        }
    };

    const notificationStatePromise = loadNotificationState();

    const renderAdminPushPanel = async () => {
        const query = new URLSearchParams(window.location.search);
        if (!window.location.pathname.startsWith('/admin') || query.get('view') !== 'pwa') {
            return;
        }

        const heading = document.querySelector('.cv-admin-content .cv-page-heading');
        if (!heading) {
            return;
        }

        try {
            const response = await fetch('/admin/push.php', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const state = await response.json();
            if (!response.ok || !state.ok) {
                return;
            }

            const existing = document.querySelector('[data-admin-push-panel]');
            if (existing) {
                existing.remove();
            }

            const panel = document.createElement('section');
            panel.className = 'cv-card cv-form';
            panel.dataset.adminPushPanel = '';

            const eyebrow = document.createElement('span');
            eyebrow.className = 'cv-eyebrow';
            eyebrow.textContent = 'WEB PUSH DELIVERY';
            panel.appendChild(eyebrow);

            const title = document.createElement('h2');
            title.textContent = state.push.configured ? 'Web Push is ready.' : 'Web Push needs configuration.';
            panel.appendChild(title);

            const status = document.createElement('p');
            status.dataset.adminPushStatus = '';
            status.textContent = `${state.stats.devices_active} active devices · ${state.stats.deliveries_pending} pending · ${state.stats.deliveries_sent} sent · ${state.stats.deliveries_failed} failed`;
            panel.appendChild(status);

            const readiness = document.createElement('p');
            readiness.textContent = `Library ${state.push.library_ready ? 'ready' : 'missing'} · subject ${state.push.subject_ready ? 'ready' : 'missing'} · public key ${state.push.public_key_ready ? 'ready' : 'missing'} · private key ${state.push.private_key_ready ? 'ready' : 'missing'}`;
            panel.appendChild(readiness);

            const dispatch = document.createElement('button');
            dispatch.type = 'button';
            dispatch.className = 'cv-button cv-button-primary';
            dispatch.dataset.adminPushDispatch = '';
            dispatch.disabled = !state.push.configured || Number(state.stats.deliveries_pending) < 1;
            dispatch.textContent = Number(state.stats.deliveries_pending) > 0
                ? `Dispatch ${state.stats.deliveries_pending} pending`
                : 'No pending deliveries';
            panel.appendChild(dispatch);

            heading.insertAdjacentElement('afterend', panel);

            const stats = document.querySelectorAll('.cv-admin-content .cv-stat');
            stats.forEach((card) => {
                const label = card.querySelector('span');
                const value = card.querySelector('strong');
                if (['Web Push', 'Web Push transport'].includes(label?.textContent?.trim()) && value) {
                    value.textContent = state.push.configured ? 'Ready' : 'Needs config';
                }
            });
        } catch (error) {
            console.error('Coveted Admin Web Push state failed:', error);
        }
    };

    renderAdminPushPanel();

    document.addEventListener('click', async (event) => {
        const enable = event.target.closest('[data-push-enable]');
        const disable = event.target.closest('[data-push-disable]');
        const adminDispatch = event.target.closest('[data-admin-push-dispatch]');

        if (enable) {
            enable.disabled = true;
            setPushStatus('Enabling…');
            try {
                await registerPushSubscription(true);
                setPushStatus('Enabled on this device');
            } catch (error) {
                setPushStatus(error instanceof Error ? error.message : 'Unable to enable notifications.', true);
            } finally {
                enable.disabled = false;
            }
            return;
        }

        if (disable) {
            disable.disabled = true;
            setPushStatus('Disabling…');
            try {
                await disablePushSubscription();
                setPushStatus('Disabled on this device');
            } catch (error) {
                setPushStatus(error instanceof Error ? error.message : 'Unable to disable notifications.', true);
            } finally {
                disable.disabled = false;
            }
            return;
        }

        if (adminDispatch) {
            adminDispatch.disabled = true;
            adminDispatch.textContent = 'Dispatching…';
            try {
                const state = notificationState || await notificationStatePromise;
                if (!state?.csrf_token) {
                    throw new Error('Admin session state is unavailable.');
                }
                const body = new URLSearchParams({
                    csrf_token: state.csrf_token,
                    action: 'dispatch'
                });
                const response = await fetch('/admin/push.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: body.toString()
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Unable to dispatch Web Push.');
                }
                await renderAdminPushPanel();
            } catch (error) {
                adminDispatch.disabled = false;
                adminDispatch.textContent = error instanceof Error ? error.message : 'Dispatch failed';
            }
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form');
        if (!form) {
            return;
        }

        const submitterMessage = event.submitter?.dataset.confirm || '';
        const formMessage = form.dataset.confirm || '';
        const message = submitterMessage || formMessage;

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', (event) => {
        const control = event.target.closest('[data-submit-on-change]');
        if (!control || !control.form) {
            return;
        }

        control.form.requestSubmit();
    });

    const root = document.querySelector('[data-coveted-player]');
    if (!root) {
        return;
    }

    const audio = root.querySelector('[data-player-audio]');
    const play = root.querySelector('[data-player-play]');
    const progress = root.querySelector('[data-player-progress]');
    const time = root.querySelector('[data-player-time]');
    const title = root.querySelector('[data-player-title]');
    const artist = root.querySelector('[data-player-artist]');
    const artwork = root.querySelector('[data-player-artwork]');
    const close = root.querySelector('[data-player-close]');

    if (!audio || !play || !progress || !time || !title || !artist || !artwork || !close) {
        return;
    }

    let seeking = false;

    const formatTime = (seconds) => {
        if (!Number.isFinite(seconds) || seconds < 0) {
            return '0:00';
        }

        const minutes = Math.floor(seconds / 60);
        const remainder = Math.floor(seconds % 60);
        return `${minutes}:${String(remainder).padStart(2, '0')}`;
    };

    const sync = () => {
        play.textContent = audio.paused ? '▶' : '❚❚';

        if (!seeking && Number.isFinite(audio.duration) && audio.duration > 0) {
            progress.value = String(Math.round((audio.currentTime / audio.duration) * 100));
        }

        time.textContent = formatTime(audio.currentTime);
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-play-audio]');
        if (!trigger) {
            return;
        }

        event.preventDefault();

        const source = trigger.dataset.src || '';
        if (!source) {
            return;
        }

        let requestedUrl;
        let currentUrl;

        try {
            requestedUrl = new URL(source, window.location.href).href;
            currentUrl = audio.src ? new URL(audio.src, window.location.href).href : '';
        } catch {
            return;
        }

        if (currentUrl !== requestedUrl) {
            audio.src = requestedUrl;
            title.textContent = trigger.dataset.title || 'Coveted audio';
            artist.textContent = trigger.dataset.artist || '';

            const image = trigger.dataset.artwork || '';
            if (image) {
                artwork.src = image;
                artwork.hidden = false;
            } else {
                artwork.removeAttribute('src');
                artwork.hidden = true;
            }
        }

        root.hidden = false;
        audio.play().catch(sync);
    });

    play.addEventListener('click', () => {
        if (!audio.src) {
            return;
        }

        if (audio.paused) {
            audio.play().catch(sync);
        } else {
            audio.pause();
        }
    });

    close.addEventListener('click', () => {
        audio.pause();
        root.hidden = true;
    });

    progress.addEventListener('input', () => {
        seeking = true;

        if (Number.isFinite(audio.duration) && audio.duration > 0) {
            time.textContent = formatTime((Number(progress.value) / 100) * audio.duration);
        }
    });

    progress.addEventListener('change', () => {
        if (Number.isFinite(audio.duration) && audio.duration > 0) {
            audio.currentTime = (Number(progress.value) / 100) * audio.duration;
        }

        seeking = false;
    });

    audio.addEventListener('timeupdate', sync);
    audio.addEventListener('play', sync);
    audio.addEventListener('pause', sync);
    audio.addEventListener('loadedmetadata', sync);
    audio.addEventListener('ended', sync);
})();
(() => {
    const dropdowns = Array.from(document.querySelectorAll('.cv-admin-dropdown'));
    if (!dropdowns.length) {
        return;
    }

    dropdowns.forEach((dropdown) => {
        dropdown.addEventListener('toggle', () => {
            if (!dropdown.open) {
                return;
            }

            dropdowns.forEach((other) => {
                if (other !== dropdown) {
                    other.open = false;
                }
            });
        });
    });

    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => {
            if (dropdown.open && !dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        dropdowns.forEach((dropdown) => {
            if (!dropdown.open) {
                return;
            }

            dropdown.open = false;
            const summary = dropdown.querySelector(':scope > summary');
            summary?.focus();
        });
    });
})();
