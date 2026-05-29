const CACHE = 'wh-v6';

const STATIC = [
    '/wh-manifest.json',
    '/wh-icon-192.png',
    '/wh-icon-512.png',
];

// Install — cache doar iconițe/manifest, niciodată HTML
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE)
            .then(c => c.addAll(STATIC))
            .then(() => self.skipWaiting()) // preia controlul imediat
    );
});

// Activate — șterge cache vechi și forțează reload pe toate telefoanele
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
            .then(() => self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
            .then(clients => clients.forEach(c => c.postMessage({ type: 'SW_RELOAD' })))
    );
});

// Fetch — HTML mereu din rețea, static din cache
self.addEventListener('fetch', e => {
    if (e.request.method !== 'GET') return;

    if (e.request.mode === 'navigate') {
        // Pagini HTML: rețea întotdeauna
        e.respondWith(fetch(e.request));
        return;
    }

    // Asset-uri statice: cache first
    e.respondWith(
        caches.match(e.request).then(cached => {
            if (cached) return cached;
            return fetch(e.request).then(res => {
                if (res.ok && STATIC.some(s => new URL(e.request.url).pathname === s)) {
                    caches.open(CACHE).then(c => c.put(e.request, res.clone()));
                }
                return res;
            });
        })
    );
});

// Push notifications
self.addEventListener('push', e => {
    if (!e.data) return;
    const data = e.data.json();
    e.waitUntil(
        self.registration.showNotification(data.title, {
            body:    data.body,
            icon:    data.icon  || '/wh-icon-192.png',
            badge:   data.badge || '/wh-icon-192.png',
            vibrate: [200, 100, 200],
            data:    { url: data.url || '/wh/' },
            actions: [{ action: 'open', title: 'Deschide' }],
        })
    );
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = e.notification.data?.url || '/wh/';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            const wh = list.find(c => c.url.includes('/wh'));
            if (wh) return wh.focus();
            return clients.openWindow(url);
        })
    );
});
