<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#b91c1c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="Recepție">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/wh-manifest.json?v=2">
    <link rel="apple-touch-icon" href="/wh-icon-192.png">
    <title>@yield('title', 'Recepție Marfă')</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; }

        /* Header */
        .wh-header { background: #b91c1c; color: white; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .wh-header h1 { font-size: 18px; font-weight: 700; }
        .wh-header .header-logo { height: 28px; object-fit: contain; filter: brightness(0) invert(1); }
        .wh-header .back-btn { font-size: 24px; cursor: pointer; padding: 4px 8px; border-radius: 8px; background: rgba(255,255,255,0.15); border: none; color: white; text-decoration: none; display: flex; align-items: center; }
        .wh-header .user-btn { font-size: 13px; background: rgba(255,255,255,0.15); border: none; color: white; padding: 6px 12px; border-radius: 20px; cursor: pointer; }

        /* Content */
        .wh-content { padding: 16px; max-width: 600px; margin: 0 auto; }

        /* Card */
        .wh-card { background: white; border-radius: 16px; padding: 18px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

        /* Butoane mari */
        .btn { display: block; width: 100%; padding: 18px; border-radius: 14px; border: none; font-size: 17px; font-weight: 600; cursor: pointer; text-align: center; transition: opacity 0.15s, transform 0.1s; }
        .btn:active { opacity: 0.85; transform: scale(0.98); }
        .btn-primary { background: #b91c1c; color: white; }
        .btn-success { background: #16a34a; color: white; }
        .btn-danger  { background: #dc2626; color: white; }
        .btn-gray    { background: #e2e8f0; color: #475569; }
        .btn-sm      { padding: 12px 18px; font-size: 15px; width: auto; display: inline-block; }

        /* Order card */
        .order-card { background: white; border-radius: 16px; padding: 18px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); cursor: pointer; transition: transform 0.1s, box-shadow 0.1s; border-left: 4px solid #b91c1c; text-decoration: none; color: inherit; display: block; }
        .order-card:active { transform: scale(0.98); box-shadow: 0 0 0 rgba(0,0,0,0); }
        .order-card .number { font-size: 16px; font-weight: 700; color: #b91c1c; }
        .order-card .supplier { font-size: 15px; color: #1e293b; margin-top: 4px; }
        .order-card .meta { font-size: 13px; color: #64748b; margin-top: 6px; display: flex; gap: 12px; }
        .order-card .badge { background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        /* Item recepție */
        .item-row { background: white; border-radius: 14px; padding: 16px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); }
        .item-name { font-size: 15px; font-weight: 600; margin-bottom: 4px; color: #b91c1c; }
        .item-sku  { font-size: 12px; color: #94a3b8; margin-bottom: 10px; }
        .item-ordered { font-size: 13px; color: #64748b; margin-bottom: 10px; }
        .qty-control { display: flex; align-items: center; gap: 0; border-radius: 12px; overflow: hidden; border: 2px solid #e2e8f0; }
        .qty-btn { width: 58px; height: 58px; border: none; background: #f1f5f9; font-size: 28px; font-weight: 300; cursor: pointer; color: #1e293b; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .qty-btn:active { background: #e2e8f0; }
        .qty-input { flex: 1; border: none; text-align: center; font-size: 24px; font-weight: 700; color: #1e293b; height: 58px; background: white; outline: none; min-width: 0; }

        /* Status banner */
        .banner { padding: 14px 16px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; font-weight: 500; }
        .banner-info    { background: #fee2e2; color: #b91c1c; }
        .banner-success { background: #dcfce7; color: #16a34a; }
        .banner-warning { background: #fef3c7; color: #92400e; }
        .banner-error   { background: #fee2e2; color: #dc2626; }

        /* Offline indicator */
        .offline-bar { background: #f59e0b; color: white; text-align: center; padding: 8px; font-size: 13px; font-weight: 600; display: none; }
        body.offline .offline-bar { display: block; }

        /* Empty state */
        .empty { text-align: center; padding: 48px 16px; color: #94a3b8; }
        .empty svg { width: 64px; height: 64px; margin-bottom: 16px; }
        .empty h2 { font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .empty p  { font-size: 14px; }

        /* Notes textarea */
        textarea { width: 100%; border: 2px solid #e2e8f0; border-radius: 12px; padding: 14px; font-size: 15px; font-family: inherit; resize: none; outline: none; }
        textarea:focus { border-color: #b91c1c; }

        /* Login */
        .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; background: #b91c1c; }
        .login-card { background: white; border-radius: 24px; padding: 32px 24px; width: 100%; max-width: 400px; }
        .login-logo { text-align: center; margin-bottom: 28px; }
        .login-logo h1 { font-size: 24px; font-weight: 800; color: #b91c1c; }
        .login-logo p  { font-size: 14px; color: #64748b; margin-top: 4px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .form-input { width: 100%; border: 2px solid #e2e8f0; border-radius: 12px; padding: 16px; font-size: 16px; font-family: inherit; outline: none; }
        .form-input:focus { border-color: #b91c1c; }
        .form-error { color: #dc2626; font-size: 13px; margin-top: 4px; }

        /* Loading spinner */
        .spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Saved queue badge */
        .queue-badge { background: #f59e0b; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; margin-left: 6px; }
    </style>
</head>
<body>
    <div class="offline-bar">⚡ Ești offline — recepțiile se salvează local și se trimit când revii online</div>

    {{-- Push notification prompt --}}
    <div id="push-prompt" style="display:none; position:fixed; bottom:16px; left:16px; right:16px; z-index:500;
        background:#1e293b; color:white; border-radius:16px; padding:16px 18px;
        box-shadow:0 8px 32px rgba(0,0,0,0.3); align-items:center; gap:12px; flex-wrap:wrap">
        <div style="flex:1; min-width:200px">
            <div style="font-weight:700; font-size:14px; margin-bottom:3px">🔔 Notificări comenzi noi</div>
            <div style="font-size:12px; color:#94a3b8">Primești notificare când apare o comandă de recepționat</div>
        </div>
        <div style="display:flex; gap:8px; flex-shrink:0">
            <button onclick="dismissPushPrompt()"
                style="padding:8px 14px; border-radius:8px; border:none; background:#334155; color:#94a3b8; font-size:13px; cursor:pointer">
                Nu acum
            </button>
            <button onclick="requestPushPermission()"
                style="padding:8px 14px; border-radius:8px; border:none; background:#b91c1c; color:white; font-size:13px; font-weight:700; cursor:pointer">
                Activează
            </button>
        </div>
    </div>

    @yield('body')

    <script>
        // ── PIN lock (inactivitate 30 min) ───────────────────────────────────
        (function() {
            const PIN_TIMEOUT = 30 * 60 * 1000; // 30 minute
            const path = window.location.pathname;
            const isProtected = (path === '/wh/' || /^\/wh\/\d+/.test(path) || path === '/wh/orders' || path === '/wh/batch' || path.startsWith('/wh/history'));

            function pinExpired() {
                const ts = parseInt(sessionStorage.getItem('wh_pin_ts') || '0', 10);
                return !ts || (Date.now() - ts) > PIN_TIMEOUT;
            }

            function touchActivity() {
                sessionStorage.setItem('wh_pin_ts', String(Date.now()));
            }

            if (isProtected) {
                if (!sessionStorage.getItem('wh_pin_ok') || pinExpired()) {
                    sessionStorage.removeItem('wh_pin_ok');
                    sessionStorage.removeItem('wh_pin_ts');
                    window.location.replace('/wh/pin');
                    return;
                }
                // Refresh timestamp la orice interacțiune
                ['click', 'touchstart', 'keydown'].forEach(ev =>
                    document.addEventListener(ev, touchActivity, { passive: true })
                );
            }
        })();

        // Offline detection
        function updateOnlineStatus() {
            document.body.classList.toggle('offline', !navigator.onLine);
        }
        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();

        // Register service worker + auto-reload când SW se actualizează
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/wh-sw.js').catch(() => {});
            navigator.serviceWorker.addEventListener('message', e => {
                if (e.data?.type === 'SW_RELOAD') window.location.reload();
            });
        }

        // CSRF helper
        const CSRF = document.querySelector('meta[name=csrf-token]')?.content;

        // IndexedDB queue for offline receptions
        const DB_NAME = 'wh_queue';
        const DB_STORE = 'receptions';

        function openDb() {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open(DB_NAME, 1);
                req.onupgradeneeded = e => e.target.result.createObjectStore(DB_STORE, { keyPath: 'orderId' });
                req.onsuccess = e => resolve(e.target.result);
                req.onerror = () => reject(req.error);
            });
        }

        async function saveToQueue(orderId, payload) {
            const db = await openDb();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(DB_STORE, 'readwrite');
                tx.objectStore(DB_STORE).put({ orderId, payload, savedAt: Date.now() });
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
        }

        async function getQueue() {
            const db = await openDb();
            return new Promise((resolve, reject) => {
                const req = db.transaction(DB_STORE, 'readonly').objectStore(DB_STORE).getAll();
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        }

        async function removeFromQueue(orderId) {
            const db = await openDb();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(DB_STORE, 'readwrite');
                tx.objectStore(DB_STORE).delete(orderId);
                tx.oncomplete = resolve;
                tx.onerror = () => reject(tx.error);
            });
        }

        async function syncQueue() {
            const queue = await getQueue();
            if (!queue.length || !navigator.onLine) return;

            for (const item of queue) {
                try {
                    const res = await fetch(`/wh/${item.orderId}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                        body: JSON.stringify(item.payload),
                    });
                    if (res.ok) {
                        await removeFromQueue(item.orderId);
                    }
                } catch (e) {}
            }
        }

        window.addEventListener('online', syncQueue);
        document.addEventListener('DOMContentLoaded', syncQueue);

        // ── Push Notifications ───────────────────────────────────────────────
        async function initPush() {
            // Doar pe pagini protejate (utilizator logat)
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
            if (!sessionStorage.getItem('wh_pin_ok')) return;

            const reg = await navigator.serviceWorker.ready;

            // Verifică dacă avem deja o subscripție activă
            const existing = await reg.pushManager.getSubscription();
            if (existing) return; // deja abonat

            // Nu cere permisiune imediat — arătăm un prompt custom
            if (Notification.permission === 'denied') return;
            if (Notification.permission === 'granted') {
                await doSubscribe(reg);
                return;
            }

            // Afișăm bannerul după 3 secunde
            setTimeout(() => {
                const banner = document.getElementById('push-prompt');
                if (banner) banner.style.display = 'flex';
            }, 3000);
        }

        async function doSubscribe(reg) {
            try {
                const resp = await fetch('/wh/push/vapid-key', { headers: { 'X-CSRF-TOKEN': CSRF } });
                const { key } = await resp.json();

                const sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(key),
                });

                const json = sub.toJSON();
                await fetch('/wh/push/subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body: JSON.stringify({
                        endpoint: json.endpoint,
                        p256dh:   json.keys.p256dh,
                        auth:     json.keys.auth,
                    }),
                });

                const banner = document.getElementById('push-prompt');
                if (banner) banner.style.display = 'none';
            } catch (e) {
                console.warn('Push subscribe failed', e);
            }
        }

        async function requestPushPermission() {
            const perm = await Notification.requestPermission();
            if (perm === 'granted') {
                const reg = await navigator.serviceWorker.ready;
                await doSubscribe(reg);
            } else {
                document.getElementById('push-prompt').style.display = 'none';
            }
        }

        function dismissPushPrompt() {
            document.getElementById('push-prompt').style.display = 'none';
            sessionStorage.setItem('wh_push_dismissed', '1');
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (!sessionStorage.getItem('wh_push_dismissed')) initPush();
        });
    </script>

    @yield('scripts')
</body>
</html>
