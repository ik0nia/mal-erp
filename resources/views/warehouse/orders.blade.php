@extends('warehouse.layout')
@section('title', 'Comenzi de recepționat')

@section('body')
<div class="wh-header">
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <div style="display:flex; gap:8px; align-items:center">
        <a href="{{ route('warehouse.pin.change') }}" style="font-size:13px; background:rgba(255,255,255,0.15); border:none; color:white; padding:6px 12px; border-radius:20px; text-decoration:none">PIN</a>
        <form method="POST" action="{{ route('warehouse.logout') }}" style="margin:0">
            @csrf
            <button type="submit" class="user-btn">Ieși</button>
        </form>
    </div>
</div>

<div class="wh-content">
    {{-- Offline queue sync status --}}
    <div id="queue-banner" style="display:none" class="banner banner-warning">
        ⏳ <span id="queue-count">0</span> recepții în așteptare de sincronizare...
    </div>

    {{-- Ecran furnizori --}}
    <div id="suppliers-screen">
        <div style="display:flex; gap:10px; margin-bottom:12px">
            <button class="btn btn-gray btn-sm" onclick="refreshOrders()" style="flex:1">
                🔄 Actualizează lista
            </button>
            <a href="/wh/history" class="btn btn-gray btn-sm" style="flex:1; text-decoration:none; color:#475569; text-align:center">
                🕐 Istoric recepții
            </a>
        </div>

        <div style="position:relative; margin-bottom:16px">
            <input type="text" id="supplier-search" placeholder="Caută furnizor..."
                autocomplete="off" autocorrect="off" spellcheck="false"
                style="width:100%; padding:14px 44px 14px 16px; border-radius:12px; border:2px solid #e2e8f0; font-size:16px; color:#1e293b; background:white; box-sizing:border-box; outline:none; transition:border-color .15s"
                onfocus="this.style.borderColor='#b91c1c'" onblur="this.style.borderColor='#e2e8f0'">
            <span id="search-clear" onclick="clearSearch()" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:22px; color:#94a3b8; cursor:pointer; line-height:1">&times;</span>
        </div>

        <div id="suppliers-list"></div>
    </div>

    {{-- Ecran comenzi furnizor selectat --}}
    <div id="orders-screen" style="display:none">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px">
            <button onclick="showSuppliers()"
                style="background:#f1f5f9; border:none; font-size:28px; color:#1e293b; cursor:pointer; padding:8px 16px; border-radius:10px; line-height:1; font-weight:700">‹ Înapoi</button>
            <div id="selected-supplier-name" style="font-size:18px; font-weight:700; color:#1e293b"></div>
        </div>
        <div id="orders-list" style="padding-bottom:90px"></div>
    </div>
</div>

{{-- Batch CTA sticky --}}
<div id="batch-bar" style="display:none; position:fixed; bottom:0; left:0; right:0; background:white; border-top:2px solid #e2e8f0; padding:12px 16px; z-index:200; box-shadow:0 -4px 16px rgba(0,0,0,0.1)">
    <button onclick="goToBatch()"
        style="width:100%; padding:16px; border-radius:12px; border:none; background:#b91c1c; color:white; font-size:16px; font-weight:700; cursor:pointer">
        <span id="batch-btn-label">Recepționează batch</span>
    </button>
</div>
@endsection

@section('scripts')
<style>
    .supplier-btn {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 18px 20px;
        margin-bottom: 12px;
        border-radius: 14px;
        border: 2px solid #e2e8f0;
        background: white;
        cursor: pointer;
        text-align: left;
        transition: border-color .15s, background .15s;
    }
    .supplier-btn:active { background: #f8fafc; }
    .supplier-btn .s-name { font-size: 17px; font-weight: 700; color: #1e293b; }
    .supplier-btn .s-meta { font-size: 13px; color: #64748b; margin-top: 3px; }
    .supplier-btn .s-badge {
        background: #dc2626; color: white;
        font-size: 13px; font-weight: 700;
        padding: 4px 12px; border-radius: 20px;
        white-space: nowrap; flex-shrink: 0; margin-left: 12px;
    }
    /* Order card cu checkbox */
    .order-card-wrap { position: relative; margin-bottom: 12px; }
    .order-checkbox {
        position: absolute; top: 50%; right: 12px; transform: translateY(-50%);
        width: 44px; height: 44px; border-radius: 50%;
        border: 2px solid #cbd5e1; background: white; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: transparent; transition: all .15s;
        z-index: 10; flex-shrink: 0;
    }
    .order-checkbox.checked { background: #b91c1c; border-color: #b91c1c; color: white; }
    .order-card-link { display: block; text-decoration: none; color: inherit; padding-right: 68px; }
    .order-card-link.selected { border-color: #b91c1c; background: #fff5f5; }
</style>
<script>
    let ALL_ORDERS   = @json($orders);
    let selectedIds  = new Set();
    let currentSupplierOrders = [];

    // ── Grupare pe furnizori ─────────────────────────────────────────────────

    function groupBySupplier(orders) {
        const map = {};
        orders.forEach(o => {
            if (!map[o.supplier]) map[o.supplier] = [];
            map[o.supplier].push(o);
        });
        return map;
    }

    function renderSuppliers(orders) {
        const list = document.getElementById('suppliers-list');

        if (!orders.length) {
            list.innerHTML = `<div class="empty">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h2>Totul e recepționat!</h2>
                <p>Nu există comenzi în așteptare.</p>
            </div>`;
            return;
        }

        const groups = groupBySupplier(orders);
        list.innerHTML = Object.entries(groups).map(([supplier, ords]) => {
            const poCount = ords.length;
            return `
                <button class="supplier-btn" onclick="showOrders(${JSON.stringify(supplier).replace(/"/g, '&quot;')})">
                    <div>
                        <div class="s-name">${escHtml(supplier)}</div>
                        <div class="s-meta">${poCount} ${poCount === 1 ? 'comandă' : 'comenzi'}</div>
                    </div>
                    <span class="s-badge">${poCount}</span>
                </button>`;
        }).join('');
    }

    function showOrders(supplier) {
        selectedIds.clear();
        currentSupplierOrders = ALL_ORDERS.filter(o => o.supplier === supplier);
        document.getElementById('selected-supplier-name').textContent = supplier;
        renderOrdersList();
        document.getElementById('suppliers-screen').style.display = 'none';
        document.getElementById('orders-screen').style.display = 'block';
        updateBatchBar();
    }

    function renderOrdersList() {
        document.getElementById('orders-list').innerHTML = currentSupplierOrders.map(o => {
            const isSel = selectedIds.has(o.id);
            const isPartial = o.status === 'partially_received';
            const partialBadge = isPartial
                ? `<span style="background:#f59e0b; color:white; font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; white-space:nowrap">Parțial (${o.receptions_count})</span>`
                : '';
            const draftBadge = o.has_draft
                ? `<span style="background:#3b82f6; color:white; font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; white-space:nowrap">📋 Draft ${o.draft_user || ''}</span>`
                : '';
            return `
                <div class="order-card-wrap">
                    <a href="/wh/${o.id}" class="order-card order-card-link${isSel ? ' selected' : ''}"
                        onclick="return handleCardClick(event, ${o.id})">
                        <div class="number" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap">${o.number} ${partialBadge} ${draftBadge}</div>
                        <div class="meta">
                            <span class="badge">${o.items_count} produse</span>
                            <span>${o.created_at}</span>
                            <span>${o.total} lei</span>
                        </div>
                    </a>
                    <div class="order-checkbox${isSel ? ' checked' : ''}" onclick="toggleSelect(${o.id})">
                        ${isSel ? '✓' : ''}
                    </div>
                </div>`;
        }).join('');
    }

    function handleCardClick(event, id) {
        // Dacă există deja selecții, tap pe card = toggle selecție (nu naviga)
        if (selectedIds.size > 0) {
            event.preventDefault();
            toggleSelect(id);
            return false;
        }
        // Altfel navigare normală la single PO
        return true;
    }

    function toggleSelect(id) {
        if (selectedIds.has(id)) {
            selectedIds.delete(id);
        } else {
            selectedIds.add(id);
        }
        renderOrdersList();
        updateBatchBar();
    }

    function updateBatchBar() {
        const bar = document.getElementById('batch-bar');
        const lbl = document.getElementById('batch-btn-label');
        if (selectedIds.size >= 1) {
            bar.style.display = 'block';
            lbl.textContent = selectedIds.size === 1
                ? 'Recepționează 1 comandă'
                : `Recepționează ${selectedIds.size} comenzi împreună`;
        } else {
            bar.style.display = 'none';
        }
    }

    function goToBatch() {
        if (selectedIds.size === 0) return;
        if (selectedIds.size === 1) {
            const id = [...selectedIds][0];
            window.location.href = `/wh/${id}`;
        } else {
            window.location.href = `/wh/batch?ids=${[...selectedIds].join(',')}`;
        }
    }

    function showSuppliers() {
        selectedIds.clear();
        document.getElementById('batch-bar').style.display = 'none';
        document.getElementById('orders-screen').style.display = 'none';
        document.getElementById('suppliers-screen').style.display = 'block';
        document.getElementById('supplier-search').value = '';
        document.getElementById('search-clear').style.display = 'none';
        renderSuppliers(ALL_ORDERS);
    }

    function escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Refresh ──────────────────────────────────────────────────────────────

    async function refreshOrders() {
        if (!navigator.onLine) { alert('Ești offline. Lista nu poate fi actualizată.'); return; }
        try {
            const res = await fetch('/wh/orders', { headers: { 'X-CSRF-TOKEN': CSRF } });
            if (!res.ok || !(res.headers.get('content-type') || '').includes('json')) {
                sessionStorage.removeItem('wh_pin_ok');
                sessionStorage.removeItem('wh_pin_ts');
                window.location.href = '/wh/pin';
                return;
            }
            ALL_ORDERS = await res.json();
            localStorage.setItem('wh_orders_cache', JSON.stringify({ orders: ALL_ORDERS, ts: Date.now() }));
            renderSuppliers(ALL_ORDERS);
            showSuppliers();
        } catch(e) {
            alert('Eroare la actualizare.');
        }
    }

    // ── Offline queue ────────────────────────────────────────────────────────

    async function showQueueStatus() {
        const queue = await getQueue();
        const banner = document.getElementById('queue-banner');
        const count  = document.getElementById('queue-count');
        if (queue.length > 0) {
            count.textContent = queue.length;
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    }

    // ── Auto-refresh la revenire în foreground ───────────────────────────────
    let lastRefresh = Date.now();
    const REFRESH_COOLDOWN = 2 * 60 * 1000; // 2 minute

    async function silentRefresh() {
        if (!navigator.onLine) return;
        if (Date.now() - lastRefresh < REFRESH_COOLDOWN) return;
        try {
            const res = await fetch('/wh/orders', { headers: { 'X-CSRF-TOKEN': CSRF } });
            if (!res.ok || !(res.headers.get('content-type') || '').includes('json')) return;
            const orders = await res.json();
            lastRefresh = Date.now();
            ALL_ORDERS = orders;
            localStorage.setItem('wh_orders_cache', JSON.stringify({ orders, ts: Date.now() }));
            // Re-render doar dacă suntem pe ecranul furnizorilor (nu pe comenzi deschise)
            if (document.getElementById('suppliers-screen').style.display !== 'none') {
                renderSuppliers(ALL_ORDERS);
            }
        } catch(e) {}
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') silentRefresh();
    });

    // ── Căutare furnizori ──────────────────────────────────────────────────
    function filterSuppliers(query) {
        if (!query) {
            renderSuppliers(ALL_ORDERS);
            return;
        }
        const q = query.toLowerCase();
        const filtered = ALL_ORDERS.filter(o => o.supplier.toLowerCase().includes(q));
        renderSuppliers(filtered);
    }

    function clearSearch() {
        const input = document.getElementById('supplier-search');
        input.value = '';
        document.getElementById('search-clear').style.display = 'none';
        filterSuppliers('');
        input.focus();
    }

    document.addEventListener('DOMContentLoaded', () => {
        renderSuppliers(ALL_ORDERS);
        showQueueStatus();

        const searchInput = document.getElementById('supplier-search');
        searchInput.addEventListener('input', function() {
            const val = this.value.trim();
            document.getElementById('search-clear').style.display = val ? 'block' : 'none';
            filterSuppliers(val);
        });
    });
    window.addEventListener('online', () => { syncQueue(); showQueueStatus(); silentRefresh(); });
</script>
@endsection
