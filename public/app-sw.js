// Service worker pentru shell-ul unificat ERP Malinco (/app).
// Scope îngust /app — NU atinge /wh sau /inv (au service workerii lor).
const CACHE = 'app-v1';
const ASSETS = [
    '/app-manifest.json',
    '/wh-icon-192.png',
    '/wh-icon-512.png',
];

self.addEventListener('install', (e) => {
    self.skipWaiting();
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    // HTML / navigări: mereu din rețea (conținut proaspăt), fallback la cache dacă offline
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
        e.respondWith(fetch(req).catch(() => caches.match(req)));
        return;
    }
    // Assets (manifest, icoane): cache-first
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
});
