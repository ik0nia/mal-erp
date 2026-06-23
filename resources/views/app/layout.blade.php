<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#b91c1c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="ERP Malinco">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/app-manifest.json?v=1">
    <link rel="apple-touch-icon" href="/wh-icon-192.png">
    <title>@yield('title', 'ERP Malinco')</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F5F0E8;color:#1f2937;min-height:100vh;}
        .app-header{position:sticky;top:0;z-index:10;background:#b91c1c;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;box-shadow:0 2px 6px rgba(0,0,0,.12);}
        .app-header-left{display:flex;align-items:center;gap:.6rem;}
        .app-back{font-size:1.6rem;line-height:1;color:#fff;text-decoration:none;opacity:.9;background:rgba(255,255,255,.15);border-radius:.5rem;width:2rem;height:2rem;display:flex;align-items:center;justify-content:center;}
        .app-logo{height:26px;object-fit:contain;filter:brightness(0) invert(1);}
        .app-title{font-size:1.05rem;font-weight:700;letter-spacing:.02em;}
        .app-user{display:flex;align-items:center;gap:.6rem;font-size:.8rem;}
        .app-user-name{opacity:.92;max-width:9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
        .app-logout{background:rgba(255,255,255,.18);border:none;color:#fff;border-radius:.5rem;padding:.35rem .65rem;font-size:.75rem;font-weight:600;cursor:pointer;}
        .app-logout:active{background:rgba(255,255,255,.3);}
        .app-main{padding:1.1rem;max-width:560px;margin:0 auto;}
    </style>
    @stack('head')
</head>
<body>
    <header class="app-header">
        <div class="app-header-left">
            @hasSection('back')
                <a href="@yield('back')" class="app-back" aria-label="Înapoi">‹</a>
            @endif
            <img src="/malinco-logo.png" alt="Malinco" class="app-logo">
        </div>
        <div class="app-user">
            <span class="app-user-name">{{ auth()->user()?->name }}</span>
            <form method="POST" action="/wh/logout" style="margin:0;">
                @csrf
                <button type="submit" class="app-logout">Ieși</button>
            </form>
        </div>
    </header>

    <main class="app-main">
        @yield('content')
    </main>

    <script>
        // ── PIN gate: refolosim PIN-ul de la /wh (sessionStorage partajat pe același origin) ──
        (function () {
            const PIN_TTL = 30 * 60 * 1000; // 30 min
            const ok = sessionStorage.getItem('wh_pin_ok');
            const ts = parseInt(sessionStorage.getItem('wh_pin_ts') || '0', 10);
            const fresh = ok && ts && (Date.now() - ts < PIN_TTL);
            if (!fresh) {
                const next = encodeURIComponent(location.pathname + location.search);
                location.replace('/wh/pin?next=' + next);
                return;
            }
            // Reîmprospătăm marcajul de activitate
            sessionStorage.setItem('wh_pin_ts', String(Date.now()));
        })();

        // ── Service worker (scope îngust /app) ──
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/app-sw.js', { scope: '/app' }).catch(() => {});
        }
    </script>
    @stack('scripts')
</body>
</html>
