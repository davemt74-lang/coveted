const STATIC_CACHE = 'coveted-static-v3';
const STATIC_ASSETS = [
    '/assets/css/coveted.css',
    '/assets/js/coveted.js',
    '/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    const isStatic = url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest';
    if (!isStatic) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => cached || fetch(request).then((response) => {
            if (response.ok) {
                const copy = response.clone();
                caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
            }
            return response;
        }))
    );
});

const safeInternalTarget = (value) => {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
        return '/notifications.php';
    }
    try {
        const resolved = new URL(value, self.location.origin);
        return resolved.origin === self.location.origin
            ? resolved.pathname + resolved.search + resolved.hash
            : '/notifications.php';
    } catch {
        return '/notifications.php';
    }
};

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Coveted';
    const target = safeInternalTarget(payload.url || '/notifications.php');
    const options = {
        body: payload.body || '',
        icon: '/uploads/pwa/icon-192.png',
        badge: '/uploads/pwa/icon-192.png',
        tag: payload.notificationId || undefined,
        renotify: payload.priority === 'high',
        data: {
            url: target,
            notificationId: payload.notificationId || null,
            type: payload.type || 'general'
        }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = safeInternalTarget(event.notification.data?.url || '/notifications.php');
    const absoluteTarget = new URL(target, self.location.origin).href;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (windows) => {
            for (const client of windows) {
                if (!('focus' in client)) {
                    continue;
                }

                try {
                    await client.navigate(absoluteTarget);
                } catch {
                    // If an existing window cannot navigate, fall through to openWindow.
                    continue;
                }
                return client.focus();
            }
            return clients.openWindow(absoluteTarget);
        })
    );
});
