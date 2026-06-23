<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/inv-manifest.json">
    <title>Inventar — Malinco</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; }

        .inv-header {
            background: #0f172a; color: white; padding: 12px 16px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .inv-header .header-logo { height: 26px; object-fit: contain; filter: brightness(0) invert(1); }
        .inv-header .user-btn { font-size: 12px; background: rgba(255,255,255,0.12); border: none; color: white; padding: 5px 11px; border-radius: 20px; cursor: pointer; }

        /* Scanner mode toggle */
        #kbd-toggle {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 2px; padding: 5px 10px; border-radius: 10px; border: none; cursor: pointer;
            font-size: 11px; font-weight: 700; white-space: nowrap; line-height: 1;
            transition: background .2s, transform .1s; min-width: 48px;
        }
        #kbd-toggle:active { transform: scale(0.93); }
        #kbd-toggle .kbd-icon { font-size: 18px; line-height: 1; }
        #kbd-toggle.scanner-mode { background: rgba(255,255,255,0.12); color: white; }
        #kbd-toggle.keyboard-mode { background: rgba(255,255,255,0.9); color: #0f172a; }

        .inv-content { padding: 14px; max-width: 600px; margin: 0 auto; }

        /* Search bar */
        .search-wrap { position: relative; margin-bottom: 14px; }
        #scan-input {
            width: 100%; padding: 14px 48px 14px 14px;
            border: 2px solid #e2e8f0; border-radius: 12px;
            font-size: 16px; font-family: inherit; outline: none; background: white;
        }
        #scan-input:focus { border-color: #0f172a; }
        #scan-input.has-value { border-color: #0f172a; }
        .search-clear {
            display: none; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer; line-height: 1;
        }

        /* Autocomplete dropdown */
        .autocomplete-dropdown {
            display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px);
            background: white; border: 2px solid #0f172a; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15); z-index: 200;
            max-height: 320px; overflow-y: auto; -webkit-overflow-scrolling: touch;
        }
        .ac-item {
            padding: 12px 14px; cursor: pointer; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; gap: 10px;
        }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:active { background: #f0f9ff; }
        .ac-item-img {
            width: 40px; height: 40px; border-radius: 8px; object-fit: contain;
            background: #f8fafc; border: 1px solid #e2e8f0; flex-shrink: 0;
        }
        .ac-item-info { flex: 1; min-width: 0; }
        .ac-item-name { font-size: 14px; font-weight: 600; color: #1e293b; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .ac-item-meta { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .ac-item-stock { flex-shrink: 0; text-align: right; font-size: 16px; font-weight: 800; }
        .ac-no-results { padding: 16px; text-align: center; color: #94a3b8; font-size: 14px; }
        .ac-loading { padding: 16px; text-align: center; color: #94a3b8; font-size: 14px; }

        /* Stare inițială */
        .empty-state {
            text-align: center; padding: 60px 20px 20px; color: #94a3b8;
        }
        .empty-state .scan-icon { font-size: 72px; margin-bottom: 16px; }
        .empty-state p { font-size: 15px; }

        /* Card produs */
        #product-card { display: none; }
        .prod-header {
            background: white; border-radius: 16px; padding: 18px; margin-bottom: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); border-left: 4px solid #0f172a;
        }
        .prod-name { font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 8px; line-height: 1.3; }
        .prod-meta { font-size: 15px; color: #64748b; display: flex; flex-wrap: wrap; gap: 12px; }
        .prod-meta span { display: flex; align-items: center; gap: 4px; }
        .badge-discontinued { background: #fee2e2; color: #dc2626; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        /* Secțiuni */
        .section-title {
            font-size: 11px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px;
        }
        .section-card {
            background: white; border-radius: 14px; padding: 14px 16px; margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.07);
        }

        /* Stocuri */
        .stock-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .stock-row:last-child { border-bottom: none; padding-bottom: 0; }
        .stock-row:first-child { padding-top: 0; }
        .stock-loc { font-size: 16px; color: #475569; font-weight: 500; }
        .stock-qty { font-size: 24px; font-weight: 800; color: #0f172a; }
        .stock-unit { font-size: 14px; color: #94a3b8; margin-left: 4px; }
        .no-stock { font-size: 14px; color: #94a3b8; font-style: italic; padding: 4px 0; }

        /* Comenzi */
        .order-row {
            padding: 10px 0; border-bottom: 1px solid #f1f5f9;
            display: flex; justify-content: space-between; align-items: center; gap: 8px;
        }
        .order-row:last-child { border-bottom: none; padding-bottom: 0; }
        .order-row:first-child { padding-top: 0; }
        .order-info .order-po { font-size: 13px; font-weight: 700; color: #1e293b; }
        .order-info .order-supplier { font-size: 12px; color: #64748b; margin-top: 1px; }
        .order-right { text-align: right; flex-shrink: 0; }
        .order-qty { font-size: 16px; font-weight: 700; color: #0f172a; }
        .order-status { display: inline-block; margin-top: 3px; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-draft, .status-pending_approval { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-received { background: #f0fdf4; color: #15803d; }

        /* Card EAN necunoscut */
        #unknown-card { display: none; }
        .unknown-wrap {
            background: white; border-radius: 16px; padding: 20px; margin-bottom: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08); border-left: 4px solid #f59e0b;
        }
        .unknown-title { font-size: 16px; font-weight: 700; color: #92400e; margin-bottom: 6px; }
        .unknown-code { font-size: 20px; font-weight: 800; font-family: monospace; color: #1e293b; margin-bottom: 14px; }
        .unknown-info { font-size: 13px; color: #64748b; margin-bottom: 16px; }

        /* Search dropdown produse */
        .prod-search-wrap { position: relative; margin-bottom: 12px; }
        .prod-search-input {
            width: 100%; padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 15px; font-family: inherit; outline: none;
        }
        .prod-search-input:focus { border-color: #f59e0b; }
        .prod-dropdown {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px);
            background: white; border: 2px solid #f59e0b; border-radius: 10px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12); z-index: 100;
            max-height: 200px; overflow-y: auto; display: none;
        }
        .prod-dd-item { padding: 11px 14px; cursor: pointer; border-bottom: 1px solid #f8fafc; font-size: 14px; }
        .prod-dd-item:last-child { border-bottom: none; }
        .prod-dd-item:active { background: #fef3c7; }
        .prod-dd-sku { font-size: 11px; color: #94a3b8; margin-top: 2px; }

        .btn { display: block; width: 100%; padding: 15px; border-radius: 12px; border: none; font-size: 16px; font-weight: 700; cursor: pointer; text-align: center; }
        .btn:active { opacity: 0.85; }
        .btn-primary { background: #0f172a; color: white; }
        .btn-amber { background: #f59e0b; color: white; }
        .btn-gray { background: #e2e8f0; color: #475569; }
        .btn-sm { padding: 10px 16px; font-size: 14px; width: auto; display: inline-block; }

        .alert-success { background: #dcfce7; color: #15803d; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; font-size: 14px; font-weight: 600; }
        .alert-warning { background: #fef3c7; color: #92400e; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; font-size: 14px; }
    </style>
</head>
<body>

<div class="inv-header">
    <div style="display:flex;align-items:center;gap:10px">
        <a href="/app" aria-label="Înapoi la aplicație" style="font-size:22px;line-height:1;color:#fff;text-decoration:none;background:rgba(255,255,255,0.15);min-width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center">‹</a>
        <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    </div>
    <button id="kbd-toggle" class="scanner-mode" aria-label="Comută mod scanner/tastatură">
        <span class="kbd-icon">📷</span>
        <span class="kbd-label">scanner</span>
    </button>
</div>

<div class="inv-content">

    {{-- Search bar --}}
    <div class="search-wrap">
        <input id="scan-input" type="search" placeholder="🔍 Scanează cod sau caută după nume..."
            autocomplete="off" autocorrect="off" spellcheck="false">
        <button class="search-clear" id="search-clear" onclick="clearScan()">×</button>
        <div id="autocomplete-dropdown" class="autocomplete-dropdown"></div>
    </div>

    {{-- Stare inițială --}}
    <div id="empty-state" class="empty-state">
        <div class="scan-icon">📦</div>
        <p>Scanează un cod de bare sau introdu<br>un SKU / EAN pentru a vedea detaliile</p>
    </div>

    {{-- Loading --}}
    <div id="loading" style="display:none; text-align:center; padding:40px; color:#94a3b8; font-size:15px;">
        ⏳ Se caută...
    </div>

    {{-- Card produs găsit --}}
    <div id="product-card">
        <div class="prod-header">
            <div class="prod-name" id="prod-name">—</div>
            <div class="prod-meta" id="prod-meta"></div>
            <img id="prod-img" src="" alt="" style="display:none; width:100%; max-height:150px; object-fit:contain; border-radius:12px; background:#f8fafc; border:1px solid #e2e8f0; margin-top:14px">
        </div>

        <div class="section-title">Stoc</div>
        <div class="section-card" id="stocks-card">
            <div class="no-stock">—</div>
        </div>

        <div id="orders-section" style="display:none">
            <div class="section-title">Comenzi active — în drum</div>
            <div class="section-card" id="orders-card"></div>
        </div>

        <div class="section-title">Necesar achiziție</div>
        <div class="section-card">
            <div style="display:flex; gap:8px; align-items:center">
                <input type="number" id="necesar-qty" min="0.001" step="1" value="1"
                    style="width:78px; padding:12px; border:1px solid #cbd5e1; border-radius:10px; font-size:16px; text-align:center; font-weight:700">
                <button class="btn btn-amber" id="necesar-btn" onclick="addToNecesar()" style="flex:1">➕ Adaugă la necesar</button>
            </div>
            <div id="necesar-msg" style="display:none; margin-top:10px; font-size:14px; font-weight:600; color:#15803d; text-align:center"></div>
        </div>
    </div>

    {{-- Card EAN necunoscut --}}
    <div id="unknown-card">
        <div class="unknown-wrap">
            <div class="unknown-title">⚠️ Cod necunoscut în ERP</div>
            <div class="unknown-code" id="unknown-code">—</div>
            <div class="unknown-info" id="unknown-info">Codul scanat nu este asociat niciunui produs. Poți solicita asocierea lui la un produs existent.</div>
            <div id="already-requested" style="display:none" class="alert-warning">
                ⏳ Există deja o cerere <strong id="req-status-text"></strong> pentru acest cod.
            </div>
            <div id="request-success" style="display:none" class="alert-success">
                ✅ Cerere trimisă! Administratorii vor procesa asocierea.
            </div>
            <div id="request-form" style="display:none">
                <div class="section-title" style="margin-top:4px">Caută produs după nume sau SKU</div>
                <div class="prod-search-wrap">
                    <input id="prod-search" class="prod-search-input" type="text"
                        placeholder="Caută produs după nume sau SKU..."
                        autocomplete="off" oninput="searchProducts(this.value)">
                    <div id="prod-dropdown" class="prod-dropdown"></div>
                </div>
                <div id="selected-product" style="display:none; background:#f0fdf4; border-radius:8px; padding:10px 12px; margin-bottom:12px; font-size:14px; color:#15803d; font-weight:600;"></div>
                <div id="substitute-details" style="display:none; margin-bottom:12px"></div>
                <button class="btn btn-amber" onclick="submitEanRequest()">Solicită asociere EAN</button>
            </div>
        </div>
    </div>

</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content;

// ── PIN lock ────────────────────────────────────────────────────────────────
(function() {
    const PIN_TIMEOUT = 30 * 60 * 1000;
    const path = window.location.pathname;
    if (!path.startsWith('/inv')) return;

    function pinExpired() {
        const ts = parseInt(sessionStorage.getItem('wh_pin_ts') || '0', 10);
        return !ts || (Date.now() - ts) > PIN_TIMEOUT;
    }
    if (!sessionStorage.getItem('wh_pin_ok') || pinExpired()) {
        sessionStorage.removeItem('wh_pin_ok');
        sessionStorage.removeItem('wh_pin_ts');
        window.location.replace('/wh/pin?next=/inv/');
        return;
    }
    ['click','touchstart','keydown'].forEach(ev =>
        document.addEventListener(ev, () => sessionStorage.setItem('wh_pin_ts', String(Date.now())), { passive: true })
    );
})();

if ('serviceWorker' in navigator) navigator.serviceWorker.register('/inv-sw.js').catch(() => {});

// ── Scanner mode toggle (aceeași logică ca warehouse) ───────────────────────
(function() {
    const STORAGE_KEY = 'wh_keyboard_mode';
    let keyboardMode = localStorage.getItem(STORAGE_KEY) === '1';
    const btn = document.getElementById('kbd-toggle');
    if (!btn) return;

    function suppressKb(el) {
        if (el.id === 'scan-input') return;
        if (el.dataset.origInputmode === undefined) el.dataset.origInputmode = el.getAttribute('inputmode') || '';
        el.setAttribute('inputmode', 'none');
    }
    function restoreKb(el) {
        const orig = el.dataset.origInputmode;
        if (orig === undefined) return;
        orig ? el.setAttribute('inputmode', orig) : el.removeAttribute('inputmode');
    }

    const observer = new MutationObserver(mutations => {
        if (keyboardMode) return;
        mutations.forEach(m => m.addedNodes.forEach(node => {
            if (node.nodeType !== 1) return;
            const els = node.matches('input:not([type=hidden]):not(#scan-input), textarea')
                ? [node] : [...node.querySelectorAll('input:not([type=hidden]):not(#scan-input), textarea')];
            els.forEach(suppressKb);
        }));
    });
    observer.observe(document.body, { childList: true, subtree: true });

    function focusSearch() {
        const s = document.getElementById('scan-input');
        if (!s) return;
        s.readOnly = true; s.focus();
        requestAnimationFrame(() => requestAnimationFrame(() => { s.readOnly = false; }));
    }

    function applyMode() {
        if (keyboardMode) {
            btn.querySelector('.kbd-icon').textContent  = '⌨';
            btn.querySelector('.kbd-label').textContent = 'tastatură';
            btn.className = 'keyboard-mode';
            document.querySelectorAll('input:not([type=hidden]), textarea').forEach(restoreKb);
        } else {
            btn.querySelector('.kbd-icon').textContent  = '📷';
            btn.querySelector('.kbd-label').textContent = 'scanner';
            btn.className = 'scanner-mode';
            document.querySelectorAll('input:not([type=hidden]):not(#scan-input), textarea').forEach(suppressKb);
            setTimeout(focusSearch, 200);
        }
    }

    btn.addEventListener('click', () => {
        keyboardMode = !keyboardMode;
        localStorage.setItem(STORAGE_KEY, keyboardMode ? '1' : '0');
        applyMode();
    });

    document.addEventListener('click', function(e) {
        if (keyboardMode) return;
        const tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        setTimeout(focusSearch, 120);
    });

    applyMode();
})();

// ── Scan input logic ─────────────────────────────────────────────────────────
let scanDone = true;
let lastInputAt = 0;
let selectedProductId = null;
let autoScanTimer = null;
let acTimer = null;
let acSelectedIdx = -1;

const scanInput = document.getElementById('scan-input');
const acDropdown = document.getElementById('autocomplete-dropdown');

function isKeyboardMode() {
    return localStorage.getItem('wh_keyboard_mode') === '1';
}

// Detectează dacă textul pare cod de bare (doar cifre, 8-14 caractere)
function looksLikeBarcode(val) {
    return /^\d{6,14}$/.test(val);
}

scanInput.addEventListener('input', function(e) {
    const now = Date.now();
    const val = this.value.trim();
    const gapNewScan = lastInputAt > 0 && (now - lastInputAt) > 400;

    document.getElementById('search-clear').style.display = this.value ? 'block' : 'none';
    this.classList.toggle('has-value', !!this.value);

    // Mod scanner (cod de bare) — comportament original
    if (!isKeyboardMode()) {
        acDropdown.style.display = 'none';
        clearTimeout(acTimer);

        if (scanDone || gapNewScan) {
            scanDone = false;
            const inserted = e.data != null ? e.data : this.value;
            this.value = inserted;
            document.getElementById('product-card').style.display = 'none';
            document.getElementById('unknown-card').style.display = 'none';
            document.getElementById('empty-state').style.display = 'none';
            lastInputAt = now;

            document.getElementById('search-clear').style.display = this.value ? 'block' : 'none';
            this.classList.toggle('has-value', !!this.value);

            clearTimeout(autoScanTimer);
            if (this.value.trim().length > 1) {
                autoScanTimer = setTimeout(() => doScan(this.value.trim()), 800);
            }
            return;
        }

        lastInputAt = now;
        clearTimeout(autoScanTimer);
        if (val.length > 1) {
            autoScanTimer = setTimeout(() => doScan(val), 800);
        }
        return;
    }

    // Mod tastatură
    lastInputAt = now;
    clearTimeout(autoScanTimer);
    clearTimeout(acTimer);
    acSelectedIdx = -1;

    if (val.length < 2) {
        acDropdown.style.display = 'none';
        return;
    }

    // Dacă e cod numeric (barcode) → lookup direct
    if (looksLikeBarcode(val)) {
        acDropdown.style.display = 'none';
        autoScanTimer = setTimeout(() => doScan(val), 800);
        return;
    }

    // Text → autocomplete AJAX
    acDropdown.innerHTML = '<div class="ac-loading">Se caută...</div>';
    acDropdown.style.display = 'block';

    acTimer = setTimeout(() => doAutocomplete(val), 300);
});

scanInput.addEventListener('keydown', function(e) {
    // Navigare autocomplete cu săgeți
    const items = acDropdown.querySelectorAll('.ac-item');
    if (acDropdown.style.display === 'block' && items.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            acSelectedIdx = Math.min(acSelectedIdx + 1, items.length - 1);
            highlightAcItem(items);
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            acSelectedIdx = Math.max(acSelectedIdx - 1, 0);
            highlightAcItem(items);
            return;
        }
        if (e.key === 'Enter' && acSelectedIdx >= 0) {
            e.preventDefault();
            items[acSelectedIdx].click();
            return;
        }
    }

    if (e.key === 'Enter') {
        e.preventDefault();
        scanDone = true;
        lastInputAt = 0;
        clearTimeout(autoScanTimer);
        clearTimeout(acTimer);
        acDropdown.style.display = 'none';
        const code = this.value.trim();
        if (code.length > 1) doScan(code);
    }

    if (e.key === 'Escape') {
        acDropdown.style.display = 'none';
        acSelectedIdx = -1;
    }
});

function highlightAcItem(items) {
    items.forEach((el, i) => {
        el.style.background = i === acSelectedIdx ? '#f0f9ff' : '';
    });
    if (items[acSelectedIdx]) items[acSelectedIdx].scrollIntoView({ block: 'nearest' });
}

// ── Autocomplete AJAX ────────────────────────────────────────────────────────
async function doAutocomplete(q) {
    try {
        const res = await fetch('/inv/search?q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const products = await res.json();

        if (!products.length) {
            acDropdown.innerHTML = '<div class="ac-no-results">Niciun produs găsit</div>';
            acDropdown.style.display = 'block';
            return;
        }

        acDropdown.innerHTML = products.map((p, idx) => {
            const stockColor = p.stock > 0 ? '#15803d' : '#dc2626';
            const stockDisplay = p.stock % 1 === 0 ? p.stock : p.stock.toFixed(2);
            return `
            <div class="ac-item" data-sku="${esc(p.sku || '')}" data-id="${p.id}" onclick="selectAutocomplete(this)">
                ${p.image ? `<img class="ac-item-img" src="${esc(p.image)}" alt="">` : `<div class="ac-item-img" style="display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:18px">📦</div>`}
                <div class="ac-item-info">
                    <div class="ac-item-name">${esc(p.name)}</div>
                    <div class="ac-item-meta">${p.sku ? 'SKU: ' + esc(p.sku) : ''}</div>
                </div>
                <div class="ac-item-stock" style="color:${stockColor}">${stockDisplay}<div style="font-size:11px;font-weight:400;color:#94a3b8">${esc(p.unit || 'buc')}</div></div>
            </div>`;
        }).join('');
        acDropdown.style.display = 'block';
        acSelectedIdx = -1;
    } catch(e) {
        acDropdown.innerHTML = '<div class="ac-no-results">Eroare la căutare</div>';
    }
}

function selectAutocomplete(el) {
    const sku = el.dataset.sku;
    const name = el.querySelector('.ac-item-name')?.textContent || '';
    acDropdown.style.display = 'none';
    acSelectedIdx = -1;

    scanInput.value = name;
    document.getElementById('search-clear').style.display = 'block';
    scanInput.classList.add('has-value');

    // Lookup produs pe SKU sau nume
    doScan(sku || name);
}

function clearScan() {
    scanInput.value = '';
    scanInput.classList.remove('has-value');
    document.getElementById('search-clear').style.display = 'none';
    document.getElementById('empty-state').style.display = 'block';
    document.getElementById('product-card').style.display = 'none';
    document.getElementById('unknown-card').style.display = 'none';
    acDropdown.style.display = 'none';
    acSelectedIdx = -1;
    scanDone = true; lastInputAt = 0;
    scanInput.focus();
}

// ── Lookup produs ─────────────────────────────────────────────────────────────
async function doScan(code) {
    scanDone = true;
    lastInputAt = 0;
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('product-card').style.display = 'none';
    document.getElementById('unknown-card').style.display = 'none';
    document.getElementById('loading').style.display = 'block';

    try {
        const res  = await fetch('/inv/scan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ code }),
        });
        const data = await res.json();
        document.getElementById('loading').style.display = 'none';

        if (data.found) {
            renderProduct(data);
        } else {
            renderUnknown(code, data);
        }
    } catch(err) {
        document.getElementById('loading').style.display = 'none';
        document.getElementById('empty-state').style.display = 'block';
    }
}

// ── Render produs ─────────────────────────────────────────────────────────────
let __prodId = null;
function addToNecesar() {
    if (!__prodId) return;
    const qty = parseFloat(document.getElementById('necesar-qty').value) || 1;
    const btn = document.getElementById('necesar-btn');
    btn.disabled = true; btn.textContent = '...';
    fetch('/inv/necesar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ woo_product_id: __prodId, quantity: qty }),
    }).then(r => r.json()).then(j => {
        if (j.ok) {
            const m = document.getElementById('necesar-msg');
            m.style.display = 'block';
            m.textContent = `✅ Adăugat la necesar (${j.total_items} în coș)`;
            btn.textContent = '✓ Adăugat';
            setTimeout(() => { btn.disabled = false; btn.textContent = '➕ Adaugă la necesar'; }, 1500);
        } else { btn.disabled = false; btn.textContent = '➕ Adaugă la necesar'; }
    }).catch(() => { btn.disabled = false; btn.textContent = '➕ Adaugă la necesar'; });
}

function renderProduct(data) {
    const p = data.product;

    document.getElementById('prod-name').textContent = p.name;

    // Necesar: reset UI + reține produsul curent
    __prodId = p.id;
    document.getElementById('necesar-qty').value = 1;
    const nbtn = document.getElementById('necesar-btn');
    nbtn.disabled = false; nbtn.textContent = '➕ Adaugă la necesar';
    document.getElementById('necesar-msg').style.display = 'none';

    // Poză produs
    const imgEl = document.getElementById('prod-img');
    if (p.image) {
        imgEl.src = p.image;
        imgEl.style.display = 'block';
    } else {
        imgEl.style.display = 'none';
    }

    let meta = [];
    if (p.sku)   meta.push(`<span>SKU: <strong>${esc(p.sku)}</strong></span>`);
    if (p.brand) meta.push(`<span>${esc(p.brand)}</span>`);
    if (p.ean_carton) meta.push(`<span>EAN: ${esc(p.ean_carton)}</span>`);
    if (p.is_discontinued) meta.push(`<span class="badge-discontinued">Discontinuat</span>`);
    if (p.price) meta.push(`<span style="display:block; width:100%; font-size:28px; font-weight:800; color:#dc2626; margin-top:4px">${parseFloat(p.price).toFixed(2)} lei</span>`);
    document.getElementById('prod-meta').innerHTML = meta.join('');

    // Stocuri — roșu dacă 0, verde dacă >0
    const stocksEl = document.getElementById('stocks-card');
    if (data.stocks.length) {
        const totalQty = data.stocks.reduce((sum, s) => sum + s.quantity, 0);
        stocksEl.innerHTML = data.stocks.map(s => {
            const qtyColor = s.quantity > 0 ? '#15803d' : '#dc2626';
            const qtyDisplay = s.quantity % 1 === 0 ? s.quantity : s.quantity.toFixed(2);
            return `<div class="stock-row">
                <span class="stock-loc">${esc(s.location)}</span>
                <span><span class="stock-qty" style="color:${qtyColor}">${qtyDisplay}</span><span class="stock-unit">${esc(s.unit)}</span></span>
            </div>`;
        }).join('') + (data.stocks.length > 1 ? `
            <div class="stock-row" style="border-top:2px solid #e2e8f0; margin-top:4px; padding-top:10px">
                <span class="stock-loc" style="font-weight:700; color:#0f172a">Total</span>
                <span><span class="stock-qty" style="color:${totalQty > 0 ? '#15803d' : '#dc2626'}">${totalQty % 1 === 0 ? totalQty : totalQty.toFixed(2)}</span><span class="stock-unit">${esc(data.stocks[0].unit)}</span></span>
            </div>` : '');
    } else {
        stocksEl.innerHTML = '<div style="font-size:15px; font-weight:700; color:#dc2626; padding:8px 0">Stoc 0 — nicio locație</div>';
    }

    // Comenzi active
    const ordersSection = document.getElementById('orders-section');
    const ordersEl = document.getElementById('orders-card');
    if (data.orders.length) {
        ordersSection.style.display = 'block';
        const totalOnOrder = data.orders.reduce((sum, o) => sum + o.quantity, 0);
        ordersEl.innerHTML = data.orders.map(o => {
            const statusColors = {
                draft: '#92400e', pending_approval: '#92400e',
                approved: '#15803d', sent: '#1d4ed8', received: '#15803d',
            };
            const dotColor = statusColors[o.status] || '#64748b';
            return `<div class="order-row">
                <div class="order-info">
                    <div class="order-po">${esc(o.po_number)}</div>
                    <div class="order-supplier">${esc(o.supplier)}${o.sent_at ? ' · trimis ' + o.sent_at : ''}</div>
                </div>
                <div class="order-right">
                    <div class="order-qty">${o.quantity} <small style="font-weight:400;font-size:12px">${esc(o.unit)}</small></div>
                    <span class="order-status status-${o.status}">${esc(o.status_label)}</span>
                </div>
            </div>`;
        }).join('') + `
            <div style="border-top:2px solid #e2e8f0; margin-top:6px; padding-top:10px; display:flex; justify-content:space-between; align-items:center">
                <span style="font-size:13px; font-weight:700; color:#0f172a">Total comandat</span>
                <span style="font-size:18px; font-weight:700; color:#1d4ed8">${totalOnOrder} <small style="font-weight:400;font-size:12px">${esc(data.orders[0].unit)}</small></span>
            </div>`;
    } else {
        ordersSection.style.display = 'none';
    }

    document.getElementById('product-card').style.display = 'block';
}

// ── Render EAN necunoscut ─────────────────────────────────────────────────────
function renderUnknown(code, data) {
    document.getElementById('unknown-code').textContent = code;
    selectedProductId = null;
    document.getElementById('prod-search').value = '';
    document.getElementById('selected-product').style.display = 'none';
    document.getElementById('substitute-details').style.display = 'none';
    document.getElementById('substitute-details').innerHTML = '';
    document.getElementById('request-success').style.display = 'none';

    const alreadyEl  = document.getElementById('already-requested');
    const formEl     = document.getElementById('request-form');

    if (data.existing_request) {
        const r = data.existing_request;
        const label = r.status === 'pending' ? 'în așteptare' : `aprobată → ${r.product}`;
        document.getElementById('req-status-text').textContent = label;
        alreadyEl.style.display = 'block';
    } else {
        alreadyEl.style.display = 'none';
    }
    // Afișăm mereu formularul de căutare (pentru a vedea detalii produs substituție)
    formEl.style.display = 'block';

    // Focus pe câmpul de căutare produs
    setTimeout(() => {
        const prodSearch = document.getElementById('prod-search');
        if (prodSearch) {
            prodSearch.setAttribute('inputmode', 'text');
            prodSearch.focus();
        }
    }, 300);

    document.getElementById('unknown-card').style.display = 'block';
}

// ── Cerere asociere EAN ───────────────────────────────────────────────────────
async function submitEanRequest() {
    const code = document.getElementById('unknown-code').textContent;
    const btn  = document.querySelector('#request-form .btn-amber');
    btn.disabled = true; btn.textContent = 'Se trimite...';

    try {
        const res  = await fetch('/inv/ean-request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ ean: code, woo_product_id: selectedProductId }),
        });
        const data = await res.json();
        if (data.ok) {
            document.getElementById('request-form').style.display = 'none';
            document.getElementById('request-success').style.display = 'block';
        }
    } catch(e) {
        btn.disabled = false; btn.textContent = 'Solicită asociere EAN';
    }
}

// ── Search produse pentru asociere ───────────────────────────────────────────
let searchTimer = null;
async function searchProducts(q) {
    const dd = document.getElementById('prod-dropdown');
    clearTimeout(searchTimer);
    if (q.length < 2) { dd.style.display = 'none'; return; }
    searchTimer = setTimeout(async () => {
        const res = await fetch('/inv/search?q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const products = await res.json();
        if (!products.length) { dd.innerHTML = '<div class="prod-dd-item" style="color:#94a3b8">Niciun produs găsit</div>'; dd.style.display = 'block'; return; }
        dd.innerHTML = products.map(p => `
            <div class="prod-dd-item" data-id="${p.id}" data-name="${esc(p.name)}" data-sku="${esc(p.sku)}" onmousedown="selectProduct(this)">
                ${esc(p.name)}<div class="prod-dd-sku">SKU: ${esc(p.sku)}</div>
            </div>`).join('');
        dd.style.display = 'block';
    }, 250);
}

async function selectProduct(el) {
    selectedProductId = parseInt(el.dataset.id);
    const sku = el.dataset.sku || '';
    document.getElementById('prod-search').value = el.dataset.name;
    document.getElementById('prod-dropdown').style.display = 'none';
    const sel = document.getElementById('selected-product');
    sel.textContent = '✓ ' + el.dataset.name;
    sel.style.display = 'block';

    // Încarcă detaliile produsului selectat (stoc + comenzi)
    const detailsEl = document.getElementById('substitute-details');
    detailsEl.innerHTML = '<div style="text-align:center; color:#94a3b8; padding:8px">⏳ Se încarcă detaliile...</div>';
    detailsEl.style.display = 'block';

    try {
        const lookupCode = sku || el.dataset.name;
        const res = await fetch('/inv/scan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ code: lookupCode }),
        });
        const data = await res.json();
        if (data.found) {
            renderSubstituteDetails(data, detailsEl);
        } else {
            detailsEl.innerHTML = '<div style="color:#94a3b8; font-size:13px; padding:4px 0">Fără informații stoc disponibile.</div>';
        }
    } catch(e) {
        detailsEl.innerHTML = '<div style="color:#dc2626; font-size:13px; padding:4px 0">Eroare la încărcarea detaliilor.</div>';
    }
}

function renderSubstituteDetails(data, container) {
    const p = data.product;
    let html = '';

    // Imagine + meta produs
    html += `<div style="background:white; border-radius:12px; padding:14px; border-left:4px solid #0f172a; margin-bottom:8px; box-shadow:0 1px 3px rgba(0,0,0,0.07)">`;
    if (p.image) {
        html += `<img src="${esc(p.image)}" style="width:100%; max-height:120px; object-fit:contain; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:10px">`;
    }
    let meta = [];
    if (p.sku) meta.push(`SKU: <strong>${esc(p.sku)}</strong>`);
    if (p.brand) meta.push(esc(p.brand));
    if (p.ean_carton) meta.push(`EAN: ${esc(p.ean_carton)}`);
    if (p.price) meta.push(`<strong style="color:#dc2626">${parseFloat(p.price).toFixed(2)} lei</strong>`);
    if (meta.length) html += `<div style="font-size:13px; color:#64748b; display:flex; flex-wrap:wrap; gap:10px">${meta.join(' · ')}</div>`;
    html += '</div>';

    // Stocuri
    if (data.stocks.length) {
        html += '<div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px">Stoc</div>';
        html += '<div style="background:white; border-radius:12px; padding:12px 14px; margin-bottom:8px; box-shadow:0 1px 3px rgba(0,0,0,0.07)">';
        data.stocks.forEach(s => {
            const qtyColor = s.quantity > 0 ? '#15803d' : '#dc2626';
            const qtyDisplay = s.quantity % 1 === 0 ? s.quantity : s.quantity.toFixed(2);
            html += `<div style="display:flex; justify-content:space-between; align-items:center; padding:4px 0">
                <span style="font-size:14px; color:#475569">${esc(s.location)}</span>
                <span style="font-size:18px; font-weight:800; color:${qtyColor}">${qtyDisplay} <small style="font-weight:400;font-size:12px;color:#94a3b8">${esc(s.unit)}</small></span>
            </div>`;
        });
        html += '</div>';
    }

    // Comenzi
    if (data.orders.length) {
        html += '<div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px">Comenzi active</div>';
        html += '<div style="background:white; border-radius:12px; padding:12px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.07)">';
        data.orders.forEach(o => {
            html += `<div style="display:flex; justify-content:space-between; align-items:center; padding:4px 0; font-size:13px">
                <div><strong>${esc(o.po_number)}</strong> <span style="color:#64748b">${esc(o.supplier)}</span></div>
                <div style="font-weight:700">${o.quantity} ${esc(o.unit)}</div>
            </div>`;
        });
        html += '</div>';
    }

    container.innerHTML = html;
}

document.addEventListener('click', e => {
    const dd = document.getElementById('prod-dropdown');
    if (!dd.parentElement?.contains(e.target)) dd.style.display = 'none';
    // Ascunde autocomplete dropdown la click în afară
    if (!scanInput.contains(e.target) && !acDropdown.contains(e.target)) {
        acDropdown.style.display = 'none';
        acSelectedIdx = -1;
    }
});

function esc(s) {
    return (s || '').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
