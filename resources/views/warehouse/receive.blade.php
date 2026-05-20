@extends('warehouse.layout')
@section('title', 'Recepție — ' . $order->number)

@section('body')
<div class="wh-header">
    <a href="{{ route('warehouse.orders') }}" class="back-btn">‹</a>
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <button id="kbd-toggle" class="scanner-mode" aria-label="Comută mod scanner/tastatură">
        <span class="kbd-icon">📷</span>
        <span class="kbd-label">scanner</span>
    </button>
</div>

<div class="wh-content">
    <div class="wh-card" style="margin-bottom:16px">
        <div style="font-weight:700; font-size:16px; margin-bottom:4px">{{ $order->supplier?->name }}</div>
        <div style="font-size:13px; color:#64748b">{{ $order->items->count() }} produse • {{ $order->created_at?->format('d.m.Y') }}</div>
    </div>

    <div id="success-banner" class="banner banner-success" style="display:none">
        ✅ <span id="success-msg">Recepție înregistrată cu succes!</span>
    </div>
    <div id="error-banner" class="banner banner-error" style="display:none">
        ❌ <span id="error-msg">Eroare la salvare.</span>
    </div>
    <div id="offline-saved" class="banner banner-warning" style="display:none">
        ⚡ Salvat local — se va trimite când revii online.
    </div>
    <div id="draft-banner" class="banner banner-warning" style="display:none; flex-direction:column; gap:8px">
        <div id="draft-banner-text" style="font-weight:600"></div>
        <div style="display:flex; gap:8px">
            <button onclick="restoreDraft()"
                style="flex:1; padding:8px 12px; border-radius:8px; border:none; background:#b45309; color:white; font-weight:700; cursor:pointer; font-size:14px">
                Restaurează
            </button>
            <button onclick="discardDraft()"
                style="flex:1; padding:8px 12px; border-radius:8px; border:1px solid #d97706; background:white; color:#92400e; font-weight:600; cursor:pointer; font-size:14px">
                Ignoră
            </button>
        </div>
    </div>

    {{-- ── Search + Scanner ────────────────────────────────────────────── --}}
    <div style="position:relative; margin-bottom:16px">
        <input type="search" id="item-search" placeholder="🔍 Caută produs după nume sau SKU..."
            oninput="filterItems(this.value)"
            autocomplete="off" autocorrect="off" spellcheck="false"
            style="width:100%; padding:13px 76px 13px 14px; border:2px solid #e2e8f0; border-radius:12px; font-size:15px; font-family:inherit; outline:none; box-sizing:border-box; background:white">
        <button id="search-clear" onclick="clearSearch()" style="display:none; position:absolute; right:46px; top:50%; transform:translateY(-50%); background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer; line-height:1; padding:4px">×</button>
        <button onclick="openScanner()" title="Scanează cod de bare"
            style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; color:#64748b; line-height:1">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                <circle cx="12" cy="13" r="4"/>
            </svg>
        </button>
    </div>

    {{-- ── Scanner modal ────────────────────────────────────────────────── --}}
    <div id="scanner-modal" style="display:none; position:fixed; inset:0; background:#000; z-index:9999; flex-direction:column">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; background:#1e293b; flex-shrink:0">
            <div style="color:white; font-weight:700; font-size:16px">📷 Scanează cod de bare</div>
            <button onclick="closeScanner()" style="background:none; border:none; color:white; font-size:26px; cursor:pointer; line-height:1; padding:0 4px">×</button>
        </div>

        {{-- Video + overlay --}}
        <div style="flex:1; position:relative; overflow:hidden; min-height:0">
            <div id="scanner-container" style="width:100%; height:100%"></div>

            {{-- Overlay: mască sus + bandă cu chenar + mască jos --}}
            <div style="position:absolute; inset:0; pointer-events:none">
                <div style="position:absolute; top:0; left:0; right:0; height:37%; background:rgba(0,0,0,.55)"></div>
                <div id="scan-band" style="position:absolute; top:37%; left:0; right:0; height:26%; box-sizing:border-box; border-top:3px solid #ef4444; border-bottom:3px solid #ef4444; transition:border-color .2s, background .2s; overflow:hidden">
                    <div class="scan-line"></div>
                </div>
                <div style="position:absolute; bottom:0; left:0; right:0; height:37%; background:rgba(0,0,0,.55)"></div>
            </div>
        </div>

        <div style="padding:12px 20px; text-align:center; color:#94a3b8; font-size:13px; background:#111; flex-shrink:0">
            Aliniază codul de bare în bandă
        </div>
        <div id="scanner-error" style="display:none; padding:12px 20px; background:#dc2626; color:white; font-size:14px; text-align:center; flex-shrink:0"></div>
    </div>

    {{-- ── Produse de confirmat ──────────────────────────────────────────── --}}
    <div id="pending-section">
        <div id="pending-header" style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px">
            De confirmat (<span id="pending-count">{{ count($items) }}</span>)
        </div>

        <div id="items-list">
            @foreach($items as $item)
            <div class="item-row" id="row_{{ $item['id'] }}" data-id="{{ $item['id'] }}" data-ordered="{{ $item['ordered_qty'] }}" data-supplier-sku="{{ $item['supplier_sku'] ?? '' }}">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px">
                    <div style="flex:1; min-width:0">
                        <div class="item-name">{{ $item['name'] }}</div>
                        @if($item['sku'])
                        <div class="item-sku">SKU: {{ $item['sku'] }}</div>
                        @endif
                        @if($item['supplier_sku'])
                        <div class="item-sku" style="color:#94a3b8">Cod furnizor: {{ $item['supplier_sku'] }}</div>
                        @endif
                    </div>
                    <button type="button" onclick="confirmItem({{ $item['id'] }})"
                        style="flex-shrink:0; padding:14px 22px; border-radius:10px; border:none; background:#16a34a; color:white; font-size:17px; font-weight:700; cursor:pointer; white-space:nowrap; line-height:1.2">
                        ✓ Confirmă
                    </button>
                </div>
                <div style="margin-top:8px">
                    <div class="item-ordered">Comandat: <strong>{{ number_format($item['ordered_qty'], 0) }} buc.</strong></div>
                </div>
                <div class="qty-control" style="margin-top:8px">
                    <button class="qty-btn" onclick="changeQty({{ $item['id'] }}, -1)">−</button>
                    <input class="qty-input" type="number" min="0" step="1"
                        id="qty_{{ $item['id'] }}"
                        value="{{ $item['qty'] }}"
                        oninput="onQtyChange({{ $item['id'] }}, {{ $item['ordered_qty'] }})"
                        inputmode="numeric">
                    <button class="qty-btn" onclick="changeQty({{ $item['id'] }}, 1)">+</button>
                </div>
                <div id="disc_{{ $item['id'] }}" style="display:none; margin-top:12px"></div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Produse neplanificate --}}
    <div id="extra-items-list"></div>
    <button type="button" class="btn btn-gray" onclick="addExtraItem()"
        style="margin-top:4px; margin-bottom:16px; border:2px dashed #cbd5e1; background:white; color:#475569; font-size:15px">
        ＋ Produs neplanificat
    </button>

    {{-- ── Extra confirmate ────────────────────────────────────────────────── --}}
    <div id="confirmed-extra-section" style="display:none; margin-bottom:16px">
        <div style="font-size:12px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px">
            Neplanificate adăugate (<span id="confirmed-extra-count">0</span>)
        </div>
        <div id="confirmed-extra-list"></div>
    </div>

    {{-- ── Produse confirmate (deasupra observațiilor) ──────────────────── --}}
    <div id="confirmed-section" style="display:none; margin-bottom:16px">
        <div style="font-size:12px; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px">
            Confirmate (<span id="confirmed-count">0</span>)
        </div>
        <div id="confirmed-list"></div>
    </div>

    <div class="wh-card" style="margin-top:4px">
        <label style="font-size:13px; font-weight:600; color:#475569; display:block; margin-bottom:8px">
            Observații (opțional)
        </label>
        <textarea id="received_notes" rows="3" placeholder="Ex: lipsuri, produse deteriorate..."></textarea>
    </div>

    {{-- Butoane rapide --}}
    <div style="display:flex; gap:10px; margin-top:8px; margin-bottom:12px">
        <button class="btn btn-gray btn-sm" onclick="setAllQty(true)" style="flex:1">
            ↺ Reset la comandat
        </button>
        <button class="btn btn-gray btn-sm" onclick="setAllQty(false)" style="flex:1">
            ✗ Pune tot 0
        </button>
    </div>

    <button type="button" id="save-draft-btn" onclick="saveDraft(true)"
        style="width:100%; padding:14px; border-radius:12px; border:2px solid #94a3b8; background:#f8fafc; color:#475569; font-size:15px; font-weight:600; cursor:pointer; margin-bottom:12px">
        💾 Salvează draft (continuă mai târziu)
    </button>

    <button id="submit-btn" class="btn btn-success" onclick="handleSubmitClick()" style="margin-bottom:32px">
        ✅ Confirmă recepția
    </button>
</div>

{{-- ── Ecran sumar (pasul 2) ─────────────────────────────────────────── --}}
<div id="summary-screen" style="display:none; position:fixed; inset:0; background:#f1f5f9; z-index:999; overflow-y:auto; padding-bottom:32px">
    <div class="wh-header">
        <button onclick="hideSummary()" class="back-btn" style="background:none;border:none;cursor:pointer">‹</button>
        <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
        <div style="width:48px"></div>
    </div>

    <div class="wh-content">
        <div class="wh-card" style="margin-bottom:16px; border-left:4px solid #0ea5e9">
            <div style="font-size:13px; font-weight:700; color:#0369a1; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px">Sumar recepție</div>
            <div id="summary-supplier" style="font-weight:700; font-size:16px"></div>
        </div>

        <div id="summary-items"></div>

        <div id="summary-extra-block" style="display:none">
            <div style="font-size:12px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.05em; margin:16px 0 8px">Produse neplanificate</div>
            <div id="summary-extra-items"></div>
        </div>

        <div id="summary-notes-block" style="display:none" class="wh-card" style="margin-top:16px; border-left:4px solid #64748b">
            <div style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px">Observații</div>
            <div id="summary-notes-text" style="font-size:14px; color:#1e293b; white-space:pre-wrap"></div>
        </div>

        <div style="display:flex; gap:10px; margin-top:24px">
            <button onclick="hideSummary()"
                style="flex:1; padding:16px; border-radius:12px; border:none; background:#dc2626; color:white; font-size:16px; font-weight:700; cursor:pointer">
                ← Revenire
            </button>
            <button id="confirm-btn" onclick="doSubmit()"
                style="flex:1; padding:16px; border-radius:12px; border:none; background:#16a34a; color:white; font-size:16px; font-weight:700; cursor:pointer">
                ✅ Confirmă
            </button>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<style>
    .disc-options { display:flex; flex-direction:column; gap:8px; margin-top:12px; padding-top:12px; border-top:2px dashed #e2e8f0; }
    .disc-label { font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; }
    .disc-btn { display:flex; align-items:center; gap:10px; width:100%; padding:12px 14px; border-radius:10px; border:2px solid #e2e8f0; background:white; cursor:pointer; font-size:14px; font-weight:500; color:#1e293b; text-align:left; transition:border-color .15s,background .15s; }
    .disc-btn:active { background:#f1f5f9; }
    .disc-btn.selected-shortfall { border-color:#dc2626; background:#fff1f1; color:#dc2626; }
    .disc-btn.selected-surplus   { border-color:#16a34a; background:#f0fdf4; color:#16a34a; }
    .disc-btn .disc-dot { width:16px; height:16px; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0; }
    .disc-btn.selected-shortfall .disc-dot { border-color:#dc2626; background:#dc2626; }
    .disc-btn.selected-surplus   .disc-dot { border-color:#16a34a; background:#16a34a; }
    .item-row.needs-reason { border-left:4px solid #f59e0b; }
    .item-row.needs-reason.reason-ok-shortfall { border-left-color:#dc2626; }
    .item-row.needs-reason.reason-ok-surplus   { border-left-color:#16a34a; }
    .extra-item-row { border-left:4px solid #7c3aed; }
    .extra-label { font-size:11px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.05em; margin-bottom:10px; }
    .extra-field { width:100%; border:2px solid #e2e8f0; border-radius:10px; padding:12px; font-size:15px; font-family:inherit; outline:none; margin-bottom:10px; box-sizing:border-box; }
    .extra-field:focus { border-color:#7c3aed; }
    .extra-dropdown { position:absolute; left:0; right:0; top:calc(100% + 4px); background:white; border:2px solid #7c3aed; border-radius:10px; box-shadow:0 4px 16px rgba(0,0,0,.12); z-index:100; max-height:220px; overflow-y:auto; }
    .extra-dd-item { padding:12px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; }
    .extra-dd-item:last-child { border-bottom:none; }
    .extra-dd-item:active { background:#f5f3ff; }

    @keyframes scanline { 0%{top:0} 50%{top:calc(100% - 2px)} 100%{top:0} }
    .scan-line { position:absolute; left:10%; right:10%; height:2px; background:linear-gradient(to right, transparent, #ef4444 30%, #ef4444 70%, transparent); animation:scanline 1.6s ease-in-out infinite; }
    #scan-band { transition: border-color .25s, background .25s; }
    #scanner-container video { width:100% !important; height:100% !important; object-fit:cover; }
    #scanner-container canvas { position:absolute; top:0; left:0; width:100% !important; height:100% !important; }

    .confirmed-card { background:white; border-radius:12px; padding:12px 14px; margin-bottom:8px; border-left:4px solid #16a34a; display:flex; justify-content:space-between; align-items:center; gap:8px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
    .confirmed-card .c-name { font-weight:600; font-size:14px; color:#1e293b; }
    .confirmed-card .c-detail { font-size:12px; color:#475569; margin-top:2px; }
    .unconfirm-btn { padding:6px 10px; border-radius:8px; border:1px solid #e2e8f0; background:white; color:#64748b; font-size:14px; cursor:pointer; flex-shrink:0; line-height:1; }
    .unconfirm-btn:active { background:#f1f5f9; }
</style>
<script>
    const ORDER_ID   = {{ $order->id }};
    const SUPPLIER_ID = {{ $order->supplier_id ?? 'null' }};
    const SUPPLIER_PRODUCTS_URL = '{{ route('warehouse.supplier.products', ['supplier' => $order->supplier_id ?? 0]) }}';
    const ITEMS      = @json($items);
    const DRAFT_KEY  = 'wh_draft_' + ORDER_ID;

    const discReasons    = {};   // id → reason string (for pending items)
    const confirmedItems = {};   // id → {qty, reason|null, invoice_position|null}
    let extraItemCounter = 0;

    const reasonLabels = {
        lipsa_stoc:      'Lipsă stoc furnizor',
        deteriorate:     'Deteriorate / returnate',
        accept_surplus:  'Surplus acceptat',
        returnez_surplus:'Surplus returnat',
    };

    // ── Helpers ──────────────────────────────────────────────────────────────

    function getQty(id) {
        return parseFloat(document.getElementById('qty_' + id)?.value) || 0;
    }

    function getEffectiveQty(id) {
        return confirmedItems[id] !== undefined ? confirmedItems[id].qty : getQty(id);
    }

    function getEffectiveReason(id) {
        return confirmedItems[id] !== undefined ? confirmedItems[id].reason : (discReasons[id] || null);
    }

    // ── Per-item confirm / unconfirm ─────────────────────────────────────────

    function confirmItem(id) {
        const item    = ITEMS.find(i => i.id === id);
        const qty     = getQty(id);
        const ordered = item.ordered_qty;

        // Dacă discrepanță — trebuie motiv selectat
        if (qty !== ordered && !discReasons[id]) {
            const row = document.getElementById('row_' + id);
            row.style.outline = '2px solid #f59e0b';
            row.scrollIntoView({ behavior:'smooth', block:'center' });
            setTimeout(() => row.style.outline = '', 2000);
            return;
        }

        // Popup poziție factură
        showPositionModal(id, qty);
    }

    function showPositionModal(id, qty) {
        const item = ITEMS.find(i => i.id === id);
        const existing = confirmedItems[id]?.invoice_position ?? '';
        document.getElementById('pos-modal-name').textContent = item.name;
        document.getElementById('pos-modal-input').value = existing;
        document.getElementById('pos-modal').style.display = 'flex';
        document.getElementById('pos-modal-error').style.display = 'none';
        document.getElementById('pos-modal-input').style.outline = '';
        const posInput = document.getElementById('pos-modal-input');
        // Restaurează inputmode numeric indiferent de modul scanner
        posInput.setAttribute('inputmode', 'numeric');
        setTimeout(() => posInput.focus(), 100);
        document.getElementById('pos-modal').dataset.itemId = id;
        document.getElementById('pos-modal').dataset.qty    = qty;
    }

    function closePositionModal() {
        document.getElementById('pos-modal').style.display = 'none';
        document.getElementById('pos-modal-error').style.display = 'none';
        document.getElementById('pos-modal-input').style.outline = '';
        // Re-suprimă inputmode după închidere (revenim la mod scanner)
        const posInput = document.getElementById('pos-modal-input');
        posInput.setAttribute('inputmode', 'none');
    }

    function confirmWithPosition() {
        const modal = document.getElementById('pos-modal');
        const id    = parseInt(modal.dataset.itemId);
        const qty   = parseFloat(modal.dataset.qty);
        const posInput = document.getElementById('pos-modal-input');
        const errorEl  = document.getElementById('pos-modal-error');
        const posVal = posInput.value.trim();

        // Obligatoriu
        if (posVal === '') {
            posInput.style.outline = '2px solid #dc2626';
            errorEl.textContent = 'Poziția este obligatorie';
            errorEl.style.display = 'block';
            posInput.focus();
            return;
        }

        const pos = parseInt(posVal);
        if (isNaN(pos) || pos < 1 || pos > 9999) {
            posInput.style.outline = '2px solid #dc2626';
            errorEl.textContent = 'Introdu un număr valid (1-9999)';
            errorEl.style.display = 'block';
            posInput.focus();
            return;
        }

        // Verifică duplicat
        const duplicate = Object.entries(confirmedItems).find(([cid, data]) => parseInt(cid) !== id && data.invoice_position === pos);
        if (duplicate) {
            const dupItem = ITEMS.find(i => i.id === parseInt(duplicate[0]));
            posInput.style.outline = '2px solid #dc2626';
            errorEl.textContent = `Poziția ${pos} este deja folosită de: ${dupItem ? dupItem.name : 'alt produs'}`;
            errorEl.style.display = 'block';
            posInput.focus();
            return;
        }

        posInput.style.outline = '';
        errorEl.style.display = 'none';
        closePositionModal();
        confirmedItems[id] = { qty, reason: discReasons[id] || null, invoice_position: pos };
        document.getElementById('row_' + id).style.display = 'none';
        clearSearch();
        renderConfirmedList();
        updatePendingCount();
        saveDraft();
    }

    function unconfirmItem(id) {
        delete confirmedItems[id];
        const row = document.getElementById('row_' + id);
        if (row) row.style.display = '';
        renderConfirmedList();
        updatePendingCount();
    }

    function renderConfirmedList() {
        const section = document.getElementById('confirmed-section');
        const list    = document.getElementById('confirmed-list');
        const entries = Object.entries(confirmedItems);

        document.getElementById('confirmed-count').textContent = entries.length;

        if (!entries.length) {
            section.style.display = 'none';
            list.innerHTML = '';
            return;
        }

        section.style.display = 'block';
        list.innerHTML = entries.map(([id, data]) => {
            const item    = ITEMS.find(i => i.id == parseInt(id));
            if (!item) return '';
            const diff    = data.qty - item.ordered_qty;
            const isOk    = diff === 0;
            const color   = diff < 0 ? '#dc2626' : (diff > 0 ? '#d97706' : '#15803d');
            const sign    = diff > 0 ? '+' : '';
            const reason  = data.reason ? reasonLabels[data.reason] : '';

            const posLabel = data.invoice_position ? `<span style="background:#f1f5f9; border-radius:6px; padding:1px 6px; font-size:11px; color:#475569; font-weight:600">poz. ${data.invoice_position}</span>` : '';
            return `<div class="confirmed-card" id="conf_${id}">
                <div style="flex:1; min-width:0">
                    <div class="c-name" style="display:flex; align-items:center; gap:6px">${item.name} ${posLabel}</div>
                    <div class="c-detail">
                        ${item.sku ? `SKU: ${item.sku} &bull; ` : ''}
                        Primit: <strong style="color:#15803d">${data.qty}</strong>
                        ${!isOk ? `<span style="color:${color}; font-weight:700"> (${sign}${diff})</span>` : ''}
                        ${reason ? `<span style="color:${color}"> — ${reason}</span>` : ''}
                    </div>
                </div>
                <button type="button" class="unconfirm-btn" onclick="unconfirmItem(${id})" title="Modifică">✎</button>
            </div>`;
        }).join('');
    }

    function updatePendingCount() {
        const pending = ITEMS.length - Object.keys(confirmedItems).length;
        document.getElementById('pending-count').textContent = pending;
    }

    function setItemZero(id) {
        document.getElementById('qty_' + id).value = 0;
        const item = ITEMS.find(i => i.id === id);
        onQtyChange(id, item.ordered_qty);
    }

    // ── Draft ────────────────────────────────────────────────────────────────

    function saveDraft(showFeedback = false) {
        const pendingQtys    = {};
        const pendingReasons = {};
        ITEMS.forEach(item => {
            if (confirmedItems[item.id] !== undefined) return;
            pendingQtys[item.id]    = getQty(item.id);
            if (discReasons[item.id]) pendingReasons[item.id] = discReasons[item.id];
        });

        // Extra items în editare (neconfirmate încă)
        const extraRows = [];
        document.querySelectorAll('.extra-item-row').forEach(row => {
            const idx = row.id.replace('extra_row_', '');
            extraRows.push({
                idx,
                name: document.getElementById('extra_name_' + idx)?.value.trim() || '',
                sku:  document.getElementById('extra_sku_'  + idx)?.value.trim() || '',
                qty:  parseFloat(document.getElementById('extra_qty_' + idx)?.value) || 1,
            });
        });

        localStorage.setItem(DRAFT_KEY, JSON.stringify({
            pendingQtys,
            pendingReasons,
            confirmedItems: JSON.parse(JSON.stringify(confirmedItems)),
            extraRows,
            confirmedExtraItems: JSON.parse(JSON.stringify(confirmedExtraItems)),
            notes:   document.getElementById('received_notes').value,
            savedAt: new Date().toISOString(),
        }));

        if (showFeedback) {
            const btn = document.getElementById('save-draft-btn');
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML    = '✅ Draft salvat!';
                btn.style.borderColor = '#16a34a';
                btn.style.color       = '#15803d';
                setTimeout(() => { btn.innerHTML = orig; btn.style.borderColor = ''; btn.style.color = ''; }, 2000);
            }
        }
    }

    function loadDraft() {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (!raw) return;
        try {
            const draft = JSON.parse(raw);
            const age   = Date.now() - new Date(draft.savedAt).getTime();
            if (age > 24 * 3600 * 1000) { localStorage.removeItem(DRAFT_KEY); return; }

            const time = new Date(draft.savedAt).toLocaleTimeString('ro-RO', { hour:'2-digit', minute:'2-digit' });
            document.getElementById('draft-banner-text').textContent = `📋 Draft salvat la ${time} — dorești să restaurezi progresul?`;
            document.getElementById('draft-banner').style.display = 'flex';
            window._pendingDraft = draft;
        } catch(e) {
            localStorage.removeItem(DRAFT_KEY);
        }
    }

    function restoreDraft() {
        const draft = window._pendingDraft;
        if (!draft) return;
        document.getElementById('draft-banner').style.display = 'none';

        // Restore pending qtys + reasons
        Object.entries(draft.pendingQtys || {}).forEach(([id, qty]) => {
            const item  = ITEMS.find(i => i.id == parseInt(id));
            const input = document.getElementById('qty_' + id);
            if (!item || !input) return;
            input.value = qty;
            if (draft.pendingReasons?.[id]) discReasons[parseInt(id)] = draft.pendingReasons[id];
            onQtyChange(parseInt(id), item.ordered_qty);
        });

        // Restore confirmed items
        Object.entries(draft.confirmedItems || {}).forEach(([id, data]) => {
            const intId = parseInt(id);
            const item  = ITEMS.find(i => i.id === intId);
            if (!item) return;
            const input = document.getElementById('qty_' + id);
            if (input) input.value = data.qty;
            if (data.reason) discReasons[intId] = data.reason;
            confirmedItems[intId] = data;
            const row = document.getElementById('row_' + id);
            if (row) row.style.display = 'none';
        });

        // Restore extra items confirmate
        (draft.confirmedExtraItems || []).forEach(row => {
            if (!row.name) return;
            confirmedExtraItems.push(row);
        });

        // Restore extra items în editare
        (draft.extraRows || []).forEach(row => {
            if (!row.name) return;
            extraItemCounter++;
            const idx = extraItemCounter;
            const div = document.createElement('div');
            div.className = 'item-row extra-item-row';
            div.id = 'extra_row_' + idx;
            div.innerHTML = buildExtraItemHtml(idx);
            document.getElementById('extra-items-list').appendChild(div);
            document.getElementById('extra_name_' + idx).value = row.name;
            document.getElementById('extra_sku_'  + idx).value = row.sku;
            document.getElementById('extra_qty_'  + idx).value = row.qty;
        });

        // Restore notes
        if (draft.notes) document.getElementById('received_notes').value = draft.notes;

        renderConfirmedList();
        updatePendingCount();
    }

    function discardDraft() {
        localStorage.removeItem(DRAFT_KEY);
        document.getElementById('draft-banner').style.display = 'none';
        window._pendingDraft = null;
    }

    // ── Extra items ──────────────────────────────────────────────────────────

    const confirmedExtraItems = []; // [{idx, name, sku, qty}]

    function buildExtraItemHtml(idx) {
        return `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
                <div class="extra-label">Produs neplanificat</div>
                <button type="button" onclick="removeExtraItem(${idx})"
                    style="background:none; border:none; color:#94a3b8; font-size:20px; cursor:pointer; padding:0 4px; line-height:1">×</button>
            </div>
            <div style="position:relative; margin-bottom:10px">
                <input class="extra-field" type="text" id="extra_name_${idx}" placeholder="Caută produs furnizor *"
                    autocomplete="off" style="margin-bottom:0; padding-right:36px"
                    oninput="extraSearch(${idx}, this.value)"
                    onfocus="extraSearch(${idx}, this.value)">
                <span id="extra_loading_${idx}" style="display:none; position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px">⏳</span>
                <div id="extra_dropdown_${idx}" class="extra-dropdown" style="display:none"></div>
            </div>
            <input class="extra-field" type="text" id="extra_sku_${idx}" placeholder="SKU / Cod" readonly
                style="margin-bottom:10px; background:#f8fafc; color:#475569">
            <div style="display:flex; align-items:center; gap:10px">
                <div class="qty-control" style="flex:1">
                    <button class="qty-btn" type="button" onclick="changeExtraQty(${idx}, -1)">−</button>
                    <input class="qty-input" type="number" min="1" step="1" id="extra_qty_${idx}" value="1" inputmode="numeric">
                    <button class="qty-btn" type="button" onclick="changeExtraQty(${idx}, 1)">+</button>
                </div>
                <button type="button" onclick="confirmExtraItem(${idx})"
                    style="flex-shrink:0; padding:14px 20px; border-radius:10px; border:none; background:#7c3aed; color:white; font-size:16px; font-weight:700; cursor:pointer; line-height:1.2">
                    ✓ Adaugă
                </button>
            </div>`;
    }

    function addExtraItem() {
        extraItemCounter++;
        const idx = extraItemCounter;
        const div = document.createElement('div');
        div.className = 'item-row extra-item-row';
        div.id = 'extra_row_' + idx;
        div.innerHTML = buildExtraItemHtml(idx);
        document.getElementById('extra-items-list').appendChild(div);
        div.querySelector('input').focus();
    }

    function confirmExtraItem(idx) {
        const nameInput = document.getElementById('extra_name_' + idx);
        const name = nameInput?.value.trim();
        if (!name) {
            nameInput.style.borderColor = '#dc2626';
            nameInput.focus();
            setTimeout(() => nameInput.style.borderColor = '', 2000);
            return;
        }
        const skuInput = document.getElementById('extra_sku_' + idx);
        const sku = skuInput?.value.trim() || '';
        if (!sku) {
            skuInput.style.borderColor = '#dc2626';
            skuInput.focus();
            setTimeout(() => skuInput.style.borderColor = '', 2000);
            return;
        }
        const qty = parseFloat(document.getElementById('extra_qty_' + idx)?.value) || 1;
        const row = document.getElementById('extra_row_' + idx);
        const wooProductId = row?.dataset.wooProductId ? parseInt(row.dataset.wooProductId) : null;

        confirmedExtraItems.push({ idx, name, sku, qty, wooProductId });
        removeExtraItem(idx);
        renderConfirmedExtraItems();
    }

    function unconfirmExtraItem(idx) {
        const i = confirmedExtraItems.findIndex(e => e.idx === idx);
        if (i === -1) return;
        const row = confirmedExtraItems.splice(i, 1)[0];
        renderConfirmedExtraItems();

        // Redeschide form-ul pentru editare
        extraItemCounter++;
        const newIdx = extraItemCounter;
        const div = document.createElement('div');
        div.className = 'item-row extra-item-row';
        div.id = 'extra_row_' + newIdx;
        div.innerHTML = buildExtraItemHtml(newIdx);
        document.getElementById('extra-items-list').appendChild(div);
        document.getElementById('extra_name_' + newIdx).value = row.name;
        document.getElementById('extra_sku_' + newIdx).value  = row.sku;
        document.getElementById('extra_qty_' + newIdx).value  = row.qty;
    }

    function renderConfirmedExtraItems() {
        const section = document.getElementById('confirmed-extra-section');
        const list    = document.getElementById('confirmed-extra-list');
        document.getElementById('confirmed-extra-count').textContent = confirmedExtraItems.length;
        if (!confirmedExtraItems.length) {
            section.style.display = 'none';
            return;
        }
        section.style.display = 'block';
        list.innerHTML = confirmedExtraItems.map(e => `
            <div class="confirmed-card" style="border-left-color:#7c3aed">
                <div style="flex:1; min-width:0">
                    <div class="c-name">${_esc(e.name)}</div>
                    <div class="c-detail">${e.sku ? 'SKU: ' + _esc(e.sku) + ' · ' : ''}Cantitate: <strong>${e.qty}</strong></div>
                </div>
                <button type="button" class="unconfirm-btn" onclick="unconfirmExtraItem(${e.idx})" title="Modifică">✎</button>
            </div>`).join('');
    }

    function collectExtraItems() {
        return confirmedExtraItems.map(e => ({
            name:           e.name,
            sku:            e.sku,
            qty:            e.qty,
            woo_product_id: e.wooProductId || null,
        }));
    }

    const _extraSearchTimers = {};

    function extraSearch(idx, q) {
        const dd = document.getElementById('extra_dropdown_' + idx);
        const loader = document.getElementById('extra_loading_' + idx);

        clearTimeout(_extraSearchTimers[idx]);

        if (!SUPPLIER_ID) return;

        if (q.length === 0) {
            // Arată primele produse la focus fără query
        }

        loader.style.display = 'inline';
        _extraSearchTimers[idx] = setTimeout(async () => {
            try {
                const url = SUPPLIER_PRODUCTS_URL + '?q=' + encodeURIComponent(q);
                const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const products = await resp.json();
                loader.style.display = 'none';
                renderExtraDropdown(idx, products);
            } catch (e) {
                loader.style.display = 'none';
            }
        }, q.length > 0 ? 250 : 0);
    }

    function _esc(s) {
        return (s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renderExtraDropdown(idx, products) {
        const dd = document.getElementById('extra_dropdown_' + idx);
        if (!products.length) {
            dd.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:#94a3b8">Niciun produs găsit</div>';
            dd.style.display = 'block';
            return;
        }
        dd.innerHTML = products.map(p => {
            const displayName = p.supplier_name || p.name;
            const eanSku = p.sku || '';
            const displaySku = p.supplier_sku || p.sku || '';
            const subtitle = displaySku ? `<div style="font-size:11px;color:#94a3b8;margin-top:2px">${_esc(displaySku)}</div>` : '';
            return `<div class="extra-dd-item" data-idx="${idx}" data-name="${_esc(displayName)}" data-sku="${_esc(eanSku)}" data-woo-product-id="${p.id || ''}" onmousedown="selectExtraProductEl(this)">
                <div style="font-size:14px;color:#1e293b;font-weight:500">${_esc(displayName)}</div>
                ${subtitle}
            </div>`;
        }).join('');
        dd.style.display = 'block';
    }

    function selectExtraProductEl(el) {
        const idx = el.dataset.idx;
        document.getElementById('extra_name_' + idx).value = el.dataset.name;
        document.getElementById('extra_sku_' + idx).value  = el.dataset.sku;
        // Stocăm woo_product_id pentru trimitere la submit
        const row = document.getElementById('extra_row_' + idx);
        if (row) row.dataset.wooProductId = el.dataset.wooProductId || '';
        document.getElementById('extra_dropdown_' + idx).style.display = 'none';
        document.getElementById('extra_qty_' + idx).focus();
    }

    document.addEventListener('click', function(e) {
        document.querySelectorAll('.extra-dropdown').forEach(dd => {
            if (!dd.parentElement?.contains(e.target)) dd.style.display = 'none';
        });
    });

    function removeExtraItem(idx) {
        document.getElementById('extra_row_' + idx)?.remove();
    }

    function changeExtraQty(idx, delta) {
        const input = document.getElementById('extra_qty_' + idx);
        input.value = Math.max(1, (parseFloat(input.value) || 1) + delta);
    }


    // ── Qty controls ─────────────────────────────────────────────────────────

    function changeQty(id, delta) {
        const item  = ITEMS.find(i => i.id === id);
        const input = document.getElementById('qty_' + id);
        input.value = Math.max(0, (parseFloat(input.value) || 0) + delta);
        onQtyChange(id, item.ordered_qty);
    }

    function setAllQty(useOrdered) {
        ITEMS.forEach(item => {
            if (confirmedItems[item.id] !== undefined) return; // sări peste confirmate
            document.getElementById('qty_' + item.id).value = useOrdered ? item.ordered_qty : 0;
            onQtyChange(item.id, item.ordered_qty);
        });
    }

    function onQtyChange(id, orderedQty) {
        const received = getQty(id);
        const row  = document.querySelector(`.item-row[data-id="${id}"]`);
        const disc = document.getElementById('disc_' + id);

        if (received === orderedQty) {
            disc.style.display = 'none';
            disc.innerHTML = '';
            delete discReasons[id];
            row.classList.remove('needs-reason', 'reason-ok-shortfall', 'reason-ok-surplus');
            return;
        }

        const isShortfall = received < orderedQty;
        row.classList.add('needs-reason');

        const options = isShortfall
            ? [{ value:'lipsa_stoc', label:'📦 Lipsă stoc furnizor' }, { value:'deteriorate', label:'🔴 Deteriorate / returnate' }]
            : [{ value:'accept_surplus', label:'✅ Accept marfa în plus' }, { value:'returnez_surplus', label:'↩️ Returnez ce e în plus' }];

        const current = discReasons[id];
        if (current) {
            const wasShortfall = ['lipsa_stoc','deteriorate'].includes(current);
            if (wasShortfall !== isShortfall) delete discReasons[id];
        }

        disc.style.display = 'block';
        disc.innerHTML = `<div class="disc-options">
            <div class="disc-label">Motivul diferenței:</div>
            ${options.map(o => `
                <button type="button" class="disc-btn${discReasons[id] === o.value ? (isShortfall ? ' selected-shortfall' : ' selected-surplus') : ''}"
                    data-value="${o.value}"
                    onclick="selectReason(${id}, '${o.value}', ${isShortfall})">
                    <div class="disc-dot"></div>${o.label}
                </button>`).join('')}
        </div>`;
    }

    function selectReason(id, value, isShortfall) {
        discReasons[id] = value;
        const row = document.querySelector(`.item-row[data-id="${id}"]`);

        if (value === 'returnez_surplus') {
            const item = ITEMS.find(i => i.id === id);
            document.getElementById('qty_' + id).value = item.ordered_qty;
            onQtyChange(id, item.ordered_qty);
            return;
        }

        row.classList.remove('reason-ok-shortfall', 'reason-ok-surplus');
        row.classList.add(isShortfall ? 'reason-ok-shortfall' : 'reason-ok-surplus');

        document.getElementById('disc_' + id).querySelectorAll('.disc-btn').forEach(btn => {
            btn.classList.remove('selected-shortfall', 'selected-surplus');
            if (btn.dataset.value === value)
                btn.classList.add(isShortfall ? 'selected-shortfall' : 'selected-surplus');
        });
    }

    // ── Validare + Sumar (pasul 1) ───────────────────────────────────────────

    function handleSubmitClick() {
        // Validare: produsele neconfirmate cu discrepanță trebuie să aibă motiv
        let missing = false;
        ITEMS.forEach(item => {
            if (confirmedItems[item.id] !== undefined) return;
            if (getQty(item.id) !== item.ordered_qty && !discReasons[item.id]) {
                const row = document.getElementById('row_' + item.id);
                row.style.outline = '2px solid #f59e0b';
                row.scrollIntoView({ behavior:'smooth', block:'center' });
                setTimeout(() => row.style.outline = '', 2000);
                missing = true;
            }
        });
        document.querySelectorAll('.extra-item-row').forEach(row => {
            const idx = row.id.replace('extra_row_', '');
            const nameInput = document.getElementById('extra_name_' + idx);
            if (!nameInput?.value.trim()) {
                nameInput.style.borderColor = '#dc2626';
                nameInput.focus();
                setTimeout(() => nameInput.style.borderColor = '', 2000);
                missing = true;
            }
        });
        if (missing) return;

        const totalReceived = ITEMS.reduce((sum, item) => sum + getEffectiveQty(item.id), 0)
            + collectExtraItems().reduce((sum, e) => sum + e.qty, 0);
        if (totalReceived === 0) {
            document.getElementById('error-msg').textContent = 'Nu poți confirma o recepție cu toate cantitățile 0.';
            document.getElementById('error-banner').style.display = 'block';
            window.scrollTo({ top:0, behavior:'smooth' });
            return;
        }

        showSummary();
    }

    function showSummary() {
        document.getElementById('summary-supplier').textContent =
            document.querySelector('.wh-card div[style*="font-weight:700"]')?.textContent || '';

        const itemsHtml = ITEMS.map(item => {
            const received  = getEffectiveQty(item.id);
            const ordered   = item.ordered_qty;
            const diff      = received - ordered;
            const isOk      = diff === 0;
            const isConf    = confirmedItems[item.id] !== undefined;
            const color     = diff < 0 ? '#dc2626' : '#16a34a';
            const sign      = diff > 0 ? '+' : '';
            const reason    = getEffectiveReason(item.id);
            const reasonTxt = reason ? reasonLabels[reason] : '';
            const confBadge = isConf ? `<span style="font-size:11px; background:#dcfce7; color:#15803d; border-radius:6px; padding:2px 7px; font-weight:700; margin-left:6px">✓</span>` : '';

            let diffBadge = '';
            if (!isOk) {
                diffBadge = `
                    <span style="margin-left:8px; font-size:12px; font-weight:700; color:${color}">${sign}${diff}</span>
                    ${reasonTxt ? `<div style="font-size:12px; color:${color}; margin-top:2px">${reasonTxt}</div>` : ''}`;
            }

            const invPos = confirmedItems[item.id]?.invoice_position;
            const posBadge = invPos ? `<span style="font-size:11px; background:#f1f5f9; color:#475569; border-radius:6px; padding:2px 7px; font-weight:600; margin-left:6px">poz. ${invPos}</span>` : '';
            return `<div class="wh-card" style="margin-bottom:10px; border-left:4px solid ${isOk ? '#16a34a' : (diff < 0 ? '#dc2626' : '#f59e0b')}">
                <div style="display:flex; align-items:center; margin-bottom:4px; flex-wrap:wrap; gap:4px">
                    <span style="font-weight:600; font-size:14px">${item.name}</span>${confBadge}${posBadge}
                </div>
                ${item.sku ? `<div style="font-size:12px; color:#64748b; margin-bottom:6px">SKU: ${item.sku}</div>` : ''}
                <div style="display:flex; align-items:baseline; flex-wrap:wrap; gap:4px; font-size:13px; color:#475569">
                    <span>Comandat: <strong>${ordered}</strong></span>
                    <span style="color:#94a3b8">→</span>
                    <span>Primit: <strong style="color:#1e293b">${received}</strong></span>
                    ${diffBadge}
                </div>
            </div>`;
        }).join('');
        document.getElementById('summary-items').innerHTML = itemsHtml;

        const extras = collectExtraItems();
        if (extras.length) {
            document.getElementById('summary-extra-block').style.display = 'block';
            document.getElementById('summary-extra-items').innerHTML = extras.map(e => `
                <div class="wh-card" style="margin-bottom:10px; border-left:4px solid #7c3aed">
                    <div style="font-weight:600; font-size:14px">${e.name}</div>
                    ${e.sku ? `<div style="font-size:12px; color:#64748b">SKU: ${e.sku}</div>` : ''}
                    <div style="font-size:13px; color:#475569; margin-top:4px">Cantitate: <strong>${e.qty}</strong></div>
                </div>`).join('');
        } else {
            document.getElementById('summary-extra-block').style.display = 'none';
        }

        const notes = document.getElementById('received_notes').value.trim();
        if (notes) {
            document.getElementById('summary-notes-block').style.display = 'block';
            document.getElementById('summary-notes-text').textContent = notes;
        } else {
            document.getElementById('summary-notes-block').style.display = 'none';
        }

        document.getElementById('summary-screen').style.display = 'block';
        document.getElementById('summary-screen').scrollTo(0, 0);
    }

    function hideSummary() {
        document.getElementById('summary-screen').style.display = 'none';
    }

    async function doSubmit() {
        const allReasons = {};
        Object.entries(discReasons).forEach(([id, r]) => { if (!confirmedItems[parseInt(id)]) allReasons[id] = r; });
        Object.entries(confirmedItems).forEach(([id, d]) => { if (d.reason) allReasons[id] = d.reason; });

        const confirmBtn = document.getElementById('confirm-btn');
        if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Se trimite...'; }
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Se trimite...';

        const payload = {
            items:          ITEMS.map(item => ({ id: item.id, qty: getEffectiveQty(item.id), reason: allReasons[item.id] || null, invoice_position: confirmedItems[item.id]?.invoice_position ?? null })),
            extra_items:    collectExtraItems(),
            received_notes: document.getElementById('received_notes').value,
        };

        if (!navigator.onLine) {
            await saveToQueue(ORDER_ID, payload);
            document.getElementById('offline-saved').style.display = 'block';
            btn.style.display = 'none';
            return;
        }

        try {
            const res  = await fetch(`/wh/${ORDER_ID}`, {
                method:  'POST',
                headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF },
                body:    JSON.stringify(payload),
            });

            // Sesiune/CSRF expirat → Laravel returnează HTML (419/302)
            if (res.status === 419 || res.redirected || res.status === 401) {
                throw new Error('Sesiunea a expirat. Se reîncarcă pagina...');
            }
            if (res.status === 403) {
                throw new Error('Comanda a fost deja recepționată sau nu mai este disponibilă.');
            }

            const ct = res.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                throw new Error('Sesiunea a expirat. Se reîncarcă pagina...');
            }

            const data = await res.json();

            if (data.ok) {
                localStorage.removeItem(DRAFT_KEY);
                hideSummary();
                document.getElementById('success-msg').textContent = data.message;
                document.getElementById('success-banner').style.display = 'block';
                document.getElementById('items-list').style.opacity      = '0.5';
                document.getElementById('items-list').style.pointerEvents= 'none';
                btn.innerHTML   = '✅ Recepționat';
                btn.style.background = '#15803d';
                setTimeout(() => { window.location.href = '/wh/'; }, 2200);
            } else {
                throw new Error(data.message || 'Eroare necunoscută');
            }
        } catch(e) {
            if (!navigator.onLine || e instanceof TypeError) {
                await saveToQueue(ORDER_ID, payload);
                document.getElementById('offline-saved').style.display = 'block';
                btn.style.display = 'none';
            } else if (e.message.includes('Se reîncarcă')) {
                hideSummary();
                document.getElementById('error-msg').textContent = e.message;
                document.getElementById('error-banner').style.display = 'block';
                sessionStorage.removeItem('wh_pin_ok');
                sessionStorage.removeItem('wh_pin_ts');
                setTimeout(() => { window.location.href = '/wh/pin'; }, 2000);
            } else {
                hideSummary();
                document.getElementById('error-msg').textContent = e.message;
                document.getElementById('error-banner').style.display = 'block';
                btn.disabled  = false;
                btn.innerHTML = '✅ Confirmă recepția';
                if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = '✅ Confirmă'; }
            }
        }
    }

    // ── Barcode scanner (Quagga2) ─────────────────────────────────────────────

    let _scannerOpen = false;

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) { resolve(); return; }
            const s = document.createElement('script');
            s.src = src; s.onload = resolve; s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    async function openScanner() {
        if (_scannerOpen) return;
        _scannerOpen = true;

        const modal = document.getElementById('scanner-modal');
        modal.style.display = 'flex';

        // Resetează search la fiecare deschidere
        clearSearch();

        try {
            await loadScript('/quagga2.min.js');
        } catch(e) {
            showScannerError('Nu s-a putut încărca librăria de scanare.');
            _scannerOpen = false;
            return;
        }

        await new Promise(r => setTimeout(r, 120));

        Quagga.init({
            inputStream: {
                name: 'Live',
                type: 'LiveStream',
                target: document.getElementById('scanner-container'),
                constraints: {
                    facingMode: 'environment',
                    width:  { ideal: 1280 },
                    height: { ideal: 720 },
                },
                area: { top: '37%', right: '0%', left: '0%', bottom: '37%' },
            },
            locator:  { patchSize: 'medium', halfSample: true },
            numOfWorkers: 0,
            decoder: {
                readers: ['ean_reader', 'ean_8_reader', 'code_128_reader', 'code_39_reader'],
            },
            locate: true,
        }, (err) => {
            if (err) {
                showScannerError('Nu s-a putut accesa camera. Verifică permisiunile browserului.');
                _scannerOpen = false;
                return;
            }
            Quagga.start();
        });

        let _detected = false, _lastCode = null, _codeHits = 0;
        Quagga.offDetected();
        Quagga.onDetected((result) => {
            if (_detected) return;
            const code = result.codeResult.code;
            if (!code) return;

            // Același cod trebuie citit de 3 ori consecutiv
            if (code === _lastCode) { _codeHits++; } else { _lastCode = code; _codeHits = 1; }
            if (_codeHits < 3) return;

            _detected = true;

            const band = document.getElementById('scan-band');
            if (band) {
                band.style.borderColor = '#22c55e';
                band.style.background  = 'rgba(34,197,94,0.18)';
                band.innerHTML = `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
                    <div style="background:rgba(0,0,0,.65);color:#22c55e;font-size:18px;font-weight:700;padding:8px 20px;border-radius:8px;letter-spacing:.05em">
                        ✓ ${code}
                    </div>
                </div>`;
            }

            setTimeout(() => {
                closeScanner();
                const input = document.getElementById('item-search');
                input.value = code;
                filterItems(code);
            }, 1200);
        });
    }

    function closeScanner() {
        try { Quagga.stop(); } catch(e) {}
        document.getElementById('scanner-modal').style.display = 'none';
        document.getElementById('scanner-error').style.display = 'none';
        _scannerOpen = false;
    }

    function showScannerError(msg) {
        const el = document.getElementById('scanner-error');
        el.textContent = msg;
        el.style.display = 'block';
    }

    // ── Search / reorder ─────────────────────────────────────────────────────

    function filterItems(query) {
        const clearBtn = document.getElementById('search-clear');
        const searchInput = document.getElementById('item-search');
        const q = query.trim().toLowerCase();

        clearBtn.style.display = q ? 'block' : 'none';
        searchInput.style.borderColor = q ? '#b91c1c' : '#e2e8f0';

        const list = document.getElementById('items-list');

        if (!q) {
            // Restore original order
            ITEMS.forEach(item => {
                const row = document.getElementById('row_' + item.id);
                if (row) list.appendChild(row);
            });
            return;
        }

        const matched   = [];
        const unmatched = [];

        ITEMS.forEach(item => {
            const row = document.getElementById('row_' + item.id);
            if (!row || confirmedItems[item.id] !== undefined) return;

            const haystack = (item.name + ' ' + (item.sku || '') + ' ' + (row.dataset.supplierSku || '')).toLowerCase();
            if (haystack.includes(q)) {
                matched.push(row);
            } else {
                unmatched.push(row);
            }
        });

        // Matched rows first, then unmatched
        matched.forEach(row => {
            row.style.outline = '2px solid #b91c1c';
            list.prepend(row);
        });
        unmatched.forEach(row => {
            row.style.outline = '';
            list.appendChild(row);
        });
    }

    function clearSearch() {
        const input = document.getElementById('item-search');
        input.value = '';
        filterItems('');
        input.focus();
    }

    // Init
    loadDraft();

    // Scanner input handling pe câmpul search
    (function() {
        const search = document.getElementById('item-search');
        if (!search) return;

        // Detectăm dacă e scanner hardware: caracterele vin foarte repede (<80ms gap)
        let lastInputAt = 0;
        let rapidChars = 0;          // câte caractere consecutive cu gap <80ms
        const SCANNER_SPEED = 80;    // ms — scannerele trimit caractere la <50ms
        const SCANNER_THRESHOLD = 4; // minim 4 caractere rapide = e scanner

        search.addEventListener('input', function(e) {
            const now = Date.now();
            const gap = lastInputAt > 0 ? (now - lastInputAt) : 9999;
            lastInputAt = now;

            // Detectăm scanner: multe caractere rapid consecutive
            if (gap < SCANNER_SPEED) {
                rapidChars++;
            } else {
                rapidChars = 0;
            }

            // Dacă e scanner (multe caractere rapide) și input-ul conține text vechi + cod nou,
            // extragem doar codul scanat (ultimele N caractere rapide)
            // Altfel (tastare manuală) lăsăm valoarea neschimbată
            filterItems(this.value);
        });

        // Enter / LF de la scanner → scroll la primul produs, curăță contorul
        search.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Dacă scannerul a trimis un cod (caractere rapide), curăță ce era înainte
                if (rapidChars >= SCANNER_THRESHOLD) {
                    // Valoarea e deja corectă — scannerul a suprascris prin IME
                    filterItems(this.value);
                }
                rapidChars = 0;
                lastInputAt = 0;
                const header = document.querySelector('.wh-header');
                const searchWrap = document.getElementById('item-search').parentElement;
                const offset = (header ? header.offsetHeight : 56) + 8;
                const top = searchWrap.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            }
        });
    })();

    // Enter pe input poziție factură
    document.getElementById('pos-modal-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') confirmWithPosition();
    });
</script>

{{-- Modal poziție factură --}}
<div id="pos-modal" style="display:none; position:fixed; inset:0; z-index:10000; background:rgba(0,0,0,0.5); align-items:flex-start; justify-content:center; padding-top:16px; padding-left:16px; padding-right:16px">
    <div style="background:white; border-radius:18px; padding:24px; width:100%; max-width:360px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
        <div style="font-weight:700; font-size:16px; color:#1e293b; margin-bottom:4px">Poziție pe factură</div>
        <div id="pos-modal-name" style="font-size:13px; color:#64748b; margin-bottom:20px; line-height:1.4"></div>

        <label style="font-size:13px; font-weight:600; color:#374151; display:block; margin-bottom:8px">
            Nr. poziție pe factura furnizorului
        </label>
        <input id="pos-modal-input" type="number" min="1" max="9999" inputmode="numeric"
            placeholder="ex: 1, 2, 3..."
            style="width:100%; padding:14px 16px; font-size:20px; font-weight:700; text-align:center; border:2px solid #e2e8f0; border-radius:12px; outline:none; box-sizing:border-box; -moz-appearance:textfield">
        <div id="pos-modal-error" style="display:none; font-size:13px; color:#dc2626; font-weight:600; margin-top:8px; text-align:center"></div>

        <div style="display:flex; gap:10px; margin-top:20px">
            <button onclick="confirmWithPosition()"
                style="flex:1; padding:14px; border-radius:12px; border:none; background:#b91c1c; color:white; font-size:15px; font-weight:700; cursor:pointer">
                Confirmă ✓
            </button>
        </div>
    </div>
</div>
@endsection
