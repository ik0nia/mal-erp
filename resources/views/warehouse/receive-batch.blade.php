@extends('warehouse.layout')
@section('title', 'Recepție batch — ' . $orders->first()?->supplier?->name)

@section('body')
<div class="wh-header">
    <a href="{{ route('warehouse.orders') }}" class="back-btn">‹</a>
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <div style="width:48px"></div>
</div>

<div class="wh-content">
    <div class="wh-card" style="margin-bottom:16px; border-left:4px solid #b91c1c">
        <div style="font-weight:700; font-size:16px; margin-bottom:6px">{{ $orders->first()?->supplier?->name }}</div>
        <div style="font-size:13px; color:#64748b; margin-bottom:8px">
            Recepție cumulată — {{ $orders->count() }} comenzi
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:6px">
            @foreach($orders as $o)
                <span style="background:#fee2e2; color:#b91c1c; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700">
                    {{ $o->number }}
                </span>
            @endforeach
        </div>
    </div>

    <div id="success-banner" class="banner banner-success" style="display:none">
        ✅ <span id="success-msg">Recepție înregistrată cu succes!</span>
    </div>
    <div id="error-banner" class="banner banner-error" style="display:none">
        ❌ <span id="error-msg">Eroare la salvare.</span>
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

        <div style="flex:1; position:relative; overflow:hidden; min-height:0">
            <div id="scanner-container" style="width:100%; height:100%"></div>
            <div style="position:absolute; inset:0; pointer-events:none">
                <div style="position:absolute; top:0; left:0; right:0; height:37%; background:rgba(0,0,0,.55)"></div>
                <div id="scan-band" style="position:absolute; top:37%; left:0; right:0; height:26%; box-sizing:border-box; border-top:3px solid #ef4444; border-bottom:3px solid #ef4444; overflow:hidden">
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
        <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px">
            De confirmat (<span id="pending-count">{{ count($mergedItems) }}</span>)
        </div>

        <div id="items-list">
            @foreach($mergedItems as $item)
            <div class="item-row" id="row_{{ $item['key'] }}" data-key="{{ $item['key'] }}" data-ordered="{{ $item['total_ordered'] }}">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px">
                    <div style="flex:1; min-width:0">
                        <div class="item-name">{{ $item['name'] }}</div>
                        @if($item['sku'])
                        <div class="item-sku">SKU: {{ $item['sku'] }}</div>
                        @endif
                    </div>
                    <button type="button" onclick="confirmItem('{{ $item['key'] }}')"
                        style="flex-shrink:0; padding:14px 22px; border-radius:10px; border:none; background:#16a34a; color:white; font-size:17px; font-weight:700; cursor:pointer; white-space:nowrap; line-height:1.2">
                        ✓ Confirmă
                    </button>
                </div>
                <div style="margin-top:8px">
                    <div class="item-ordered">
                        Comandat total: <strong>{{ number_format($item['total_ordered'], 0) }} buc.</strong>
                        @if(count($item['sub_items']) > 1)
                        <span style="color:#94a3b8; font-size:12px; margin-left:6px">
                            ({{ collect($item['sub_items'])->map(fn($s) => $s['po_number'] . ': ' . number_format($s['qty'], 0))->implode(', ') }})
                        </span>
                        @endif
                    </div>
                </div>
                <div class="qty-control" style="margin-top:8px">
                    <button class="qty-btn" type="button" onclick="changeQty('{{ $item['key'] }}', -1)">−</button>
                    <input class="qty-input" type="number" min="0" step="1"
                        id="qty_{{ $item['key'] }}"
                        value="{{ $item['total_ordered'] }}"
                        oninput="onQtyChange('{{ $item['key'] }}', {{ $item['total_ordered'] }})"
                        inputmode="numeric">
                    <button class="qty-btn" type="button" onclick="changeQty('{{ $item['key'] }}', 1)">+</button>
                </div>
                <div id="disc_{{ $item['key'] }}" style="display:none; margin-top:12px"></div>
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

    <div style="display:flex; gap:10px; margin-top:8px; margin-bottom:12px">
        <button class="btn btn-gray btn-sm" onclick="setAllQty(true)" style="flex:1">↺ Reset la comandat</button>
        <button class="btn btn-gray btn-sm" onclick="setAllQty(false)" style="flex:1">✗ Pune tot 0</button>
    </div>

    <button type="button" id="save-draft-btn" onclick="saveDraft(true)"
        style="width:100%; padding:14px; border-radius:12px; border:2px solid #94a3b8; background:#f8fafc; color:#475569; font-size:15px; font-weight:600; cursor:pointer; margin-bottom:12px">
        💾 Salvează draft (continuă mai târziu)
    </button>

    <button id="submit-btn" class="btn btn-success" onclick="handleSubmitClick()" style="margin-bottom:32px">
        ✅ Confirmă recepția
    </button>
</div>

{{-- ── Ecran sumar (pasul 2) ──────────────────────────────────────────────── --}}
<div id="summary-screen" style="display:none; position:fixed; inset:0; background:#f1f5f9; z-index:999; overflow-y:auto; padding-bottom:32px">
    <div class="wh-header">
        <button onclick="hideSummary()" class="back-btn" style="background:rgba(255,255,255,0.15);border:none;cursor:pointer">‹</button>
        <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
        <div style="width:48px"></div>
    </div>

    <div class="wh-content">
        <div class="wh-card" style="margin-bottom:16px; border-left:4px solid #0ea5e9">
            <div style="font-size:13px; font-weight:700; color:#0369a1; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px">Sumar recepție cumulată</div>
            <div id="summary-supplier" style="font-weight:700; font-size:16px"></div>
            <div id="summary-po-badges" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px"></div>
        </div>

        <div id="summary-items"></div>

        <div id="summary-extra-block" style="display:none">
            <div style="font-size:12px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.05em; margin:16px 0 8px">Produse neplanificate</div>
            <div id="summary-extra-items"></div>
        </div>

        <div id="summary-notes-block" style="display:none" class="wh-card">
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
            <button onclick="closePositionModal()"
                style="flex:1; padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:white; color:#64748b; font-size:15px; font-weight:600; cursor:pointer">
                Anulează
            </button>
            <button onclick="skipPosition()"
                id="pos-modal-skip-btn"
                style="flex:1; padding:14px; border-radius:12px; border:2px solid #f59e0b; background:#fffbeb; color:#92400e; font-size:15px; font-weight:600; cursor:pointer">
                Fără poziție →
            </button>
            <button onclick="confirmWithPosition()"
                style="flex:1; padding:14px; border-radius:12px; border:none; background:#b91c1c; color:white; font-size:15px; font-weight:700; cursor:pointer">
                Confirmă ✓
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
    .extra-field { width:100%; border:2px solid #e2e8f0; border-radius:10px; padding:12px; font-size:15px; font-family:inherit; outline:none; margin-bottom:10px; }
    .extra-field:focus { border-color:#7c3aed; }

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
    const PO_IDS     = @json($orders->pluck('id')->all());
    const MERGED     = @json($mergedItems);
    const SUPPLIER   = @json($orders->first()?->supplier?->name ?? '');
    const PO_NUMBERS = @json($orders->pluck('number')->all());
    const DRAFT_KEY  = 'wh_batch_draft_' + PO_IDS.join('_');

    const discReasons    = {}; // key → reason
    const confirmedItems = {}; // key → {qty, reason|null, invoice_position|null}
    let extraItemCounter = 0;

    // Poziție factură — coadă pentru confirmarea în masă (de la submit)
    let _bulkConfirmMode      = false;
    let _pendingPositionQueue = [];

    const reasonLabels = {
        lipsa_stoc:      'Lipsă stoc furnizor',
        deteriorate:     'Deteriorate / returnate',
        accept_surplus:  'Surplus acceptat',
        returnez_surplus:'Surplus returnat',
    };

    // ── Helpers ──────────────────────────────────────────────────────────────

    function getQty(key) {
        return parseFloat(document.getElementById('qty_' + key)?.value) || 0;
    }

    function getEffectiveQty(key) {
        return confirmedItems[key] !== undefined ? confirmedItems[key].qty : getQty(key);
    }

    function getEffectiveReason(key) {
        return confirmedItems[key] !== undefined ? confirmedItems[key].reason : (discReasons[key] || null);
    }

    // ── Per-item confirm / unconfirm ─────────────────────────────────────────

    function confirmItem(key) {
        const item    = MERGED.find(i => i.key === key);
        const qty     = getQty(key);
        const ordered = item.total_ordered;

        if (qty !== ordered && !discReasons[key]) {
            const row = document.getElementById('row_' + key);
            row.style.outline = '2px solid #f59e0b';
            row.scrollIntoView({ behavior:'smooth', block:'center' });
            setTimeout(() => row.style.outline = '', 2000);
            return;
        }

        // Popup poziție factură (poziție per linie comasată)
        showPositionModal(key, qty);
    }

    // ── Poziție factură ──────────────────────────────────────────────────────

    function showPositionModal(key, qty) {
        const item = MERGED.find(i => i.key === key);
        const existing = confirmedItems[key]?.invoice_position ?? '';
        document.getElementById('pos-modal-name').textContent = item.name;
        document.getElementById('pos-modal-input').value = existing;
        document.getElementById('pos-modal').style.display = 'flex';
        document.getElementById('pos-modal-error').style.display = 'none';
        document.getElementById('pos-modal-input').style.outline = '';
        const posInput = document.getElementById('pos-modal-input');
        posInput.setAttribute('inputmode', 'numeric');
        setTimeout(() => posInput.focus(), 100);
        document.getElementById('pos-modal').dataset.itemKey = key;
        document.getElementById('pos-modal').dataset.qty     = qty;
    }

    function closePositionModal(cancelBulk = true) {
        document.getElementById('pos-modal').style.display = 'none';
        document.getElementById('pos-modal-error').style.display = 'none';
        document.getElementById('pos-modal-input').style.outline = '';
        if (cancelBulk && _bulkConfirmMode) {
            _bulkConfirmMode = false;
            _pendingPositionQueue = [];
        }
    }

    function confirmWithPosition() {
        const modal = document.getElementById('pos-modal');
        const key   = modal.dataset.itemKey;
        const qty   = parseFloat(modal.dataset.qty);
        const posInput = document.getElementById('pos-modal-input');
        const errorEl  = document.getElementById('pos-modal-error');
        const posVal = posInput.value.trim();

        // Poziția e opțională — dacă e goală, se salvează fără
        let pos = null;
        if (posVal !== '') {
            pos = parseInt(posVal);
            if (isNaN(pos) || pos < 1 || pos > 9999) {
                posInput.style.outline = '2px solid #dc2626';
                errorEl.textContent = 'Introdu un număr valid (1-9999)';
                errorEl.style.display = 'block';
                posInput.focus();
                return;
            }
        }

        // Verifică duplicat (doar dacă s-a introdus o poziție)
        if (pos !== null) {
            const duplicate = Object.entries(confirmedItems).find(([ckey, data]) => ckey !== key && data.invoice_position === pos);
            if (duplicate) {
                const dupItem = MERGED.find(i => i.key === duplicate[0]);
                posInput.style.outline = '2px solid #dc2626';
                errorEl.textContent = `Poziția ${pos} este deja folosită de: ${dupItem ? dupItem.name : 'alt produs'}`;
                errorEl.style.display = 'block';
                posInput.focus();
                return;
            }
        }

        posInput.style.outline = '';
        errorEl.style.display = 'none';
        const wasBulk = _bulkConfirmMode;
        closePositionModal(false); // nu anula bulk mode
        applyConfirm(key, qty, pos);

        if (wasBulk) {
            _pendingPositionQueue.shift();
            processNextPendingPosition();
        }
    }

    function skipPosition() {
        const modal = document.getElementById('pos-modal');
        const key   = modal.dataset.itemKey;
        const qty   = parseFloat(modal.dataset.qty);
        const wasBulk = _bulkConfirmMode;
        closePositionModal(false);
        applyConfirm(key, qty, null);

        if (wasBulk) {
            _pendingPositionQueue.shift();
            processNextPendingPosition();
        }
    }

    function applyConfirm(key, qty, pos) {
        confirmedItems[key] = { qty, reason: discReasons[key] || null, invoice_position: pos };
        const row = document.getElementById('row_' + key);
        if (row) row.style.display = 'none';
        renderConfirmedList();
        updatePendingCount();
        saveDraft();
    }

    function processNextPendingPosition() {
        if (_pendingPositionQueue.length === 0) {
            _bulkConfirmMode = false;
            showSummary();
            return;
        }
        const key = _pendingPositionQueue[0];
        const qty = getQty(key);
        showPositionModal(key, qty);
    }

    function unconfirmItem(key) {
        delete confirmedItems[key];
        const row = document.getElementById('row_' + key);
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
        list.innerHTML = entries.map(([key, data]) => {
            const item   = MERGED.find(i => i.key === key);
            if (!item) return '';
            const diff   = data.qty - item.total_ordered;
            const isOk   = diff === 0;
            const color  = diff < 0 ? '#dc2626' : (diff > 0 ? '#d97706' : '#15803d');
            const sign   = diff > 0 ? '+' : '';
            const reason = data.reason ? reasonLabels[data.reason] : '';
            const posLabel = data.invoice_position ? `<span style="background:#f1f5f9; border-radius:6px; padding:1px 6px; font-size:11px; color:#475569; font-weight:600">poz. ${data.invoice_position}</span>` : '';

            return `<div class="confirmed-card" id="conf_${key}">
                <div style="flex:1; min-width:0">
                    <div class="c-name" style="display:flex; align-items:center; gap:6px">${item.name} ${posLabel}</div>
                    <div class="c-detail">
                        ${item.sku ? `SKU: ${item.sku} &bull; ` : ''}
                        Primit: <strong style="color:#15803d">${data.qty}</strong>
                        ${!isOk ? `<span style="color:${color}; font-weight:700"> (${sign}${diff})</span>` : ''}
                        ${reason ? `<span style="color:${color}"> — ${reason}</span>` : ''}
                    </div>
                </div>
                <button type="button" class="unconfirm-btn" onclick="unconfirmItem('${key}')" title="Modifică">✎</button>
            </div>`;
        }).join('');
    }

    function updatePendingCount() {
        const pending = MERGED.length - Object.keys(confirmedItems).length;
        document.getElementById('pending-count').textContent = pending;
    }

    function setItemZero(key) {
        document.getElementById('qty_' + key).value = 0;
        const item = MERGED.find(i => i.key === key);
        onQtyChange(key, item.total_ordered);
    }

    // ── Draft ────────────────────────────────────────────────────────────────

    function saveDraft(showFeedback = false) {
        const pendingQtys    = {};
        const pendingReasons = {};
        MERGED.forEach(item => {
            if (confirmedItems[item.key] !== undefined) return;
            pendingQtys[item.key] = getQty(item.key);
            if (discReasons[item.key]) pendingReasons[item.key] = discReasons[item.key];
        });

        const extraRows = [];
        document.querySelectorAll('.extra-item-row').forEach(row => {
            const idx = row.id.replace('extra_row_', '');
            extraRows.push({
                idx,
                name:  document.getElementById('extra_name_' + idx)?.value.trim() || '',
                sku:   document.getElementById('extra_sku_'  + idx)?.value.trim() || '',
                qty:   parseFloat(document.getElementById('extra_qty_' + idx)?.value) || 1,
                po_id: parseInt(document.getElementById('extra_po_' + idx)?.value) || PO_IDS[0],
            });
        });

        localStorage.setItem(DRAFT_KEY, JSON.stringify({
            pendingQtys,
            pendingReasons,
            confirmedItems: JSON.parse(JSON.stringify(confirmedItems)),
            extraRows,
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

        Object.entries(draft.pendingQtys || {}).forEach(([key, qty]) => {
            const item  = MERGED.find(i => i.key === key);
            const input = document.getElementById('qty_' + key);
            if (!item || !input) return;
            input.value = qty;
            if (draft.pendingReasons?.[key]) discReasons[key] = draft.pendingReasons[key];
            onQtyChange(key, item.total_ordered);
        });

        Object.entries(draft.confirmedItems || {}).forEach(([key, data]) => {
            const item  = MERGED.find(i => i.key === key);
            if (!item) return;
            const input = document.getElementById('qty_' + key);
            if (input) input.value = data.qty;
            if (data.reason) discReasons[key] = data.reason;
            confirmedItems[key] = data;
            const row = document.getElementById('row_' + key);
            if (row) row.style.display = 'none';
        });

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
            const poSel = document.getElementById('extra_po_' + idx);
            if (poSel && row.po_id) poSel.value = row.po_id;
        });

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

    function buildExtraItemHtml(idx) {
        const poOptions = PO_IDS.map((id, i) => `<option value="${id}">${PO_NUMBERS[i]}</option>`).join('');
        return `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
                <div class="extra-label">Produs neplanificat</div>
                <button type="button" onclick="removeExtraItem(${idx})"
                    style="background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;padding:0 4px;line-height:1">×</button>
            </div>
            <input class="extra-field" type="text" id="extra_name_${idx}" placeholder="Nume produs *" autocomplete="off">
            <input class="extra-field" type="text" id="extra_sku_${idx}"  placeholder="SKU / EAN *" autocomplete="off" style="margin-bottom:10px">
            <div class="qty-control" style="margin-bottom:10px">
                <button class="qty-btn" type="button" onclick="changeExtraQty(${idx}, -1)">−</button>
                <input class="qty-input" type="number" min="1" step="1" id="extra_qty_${idx}" value="1" inputmode="numeric">
                <button class="qty-btn" type="button" onclick="changeExtraQty(${idx}, 1)">+</button>
            </div>
            <div style="font-size:12px; font-weight:600; color:#475569; margin-bottom:6px">Alocă la comanda:</div>
            <select id="extra_po_${idx}" class="extra-field" style="margin-bottom:0">${poOptions}</select>`;
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

    function removeExtraItem(idx) {
        document.getElementById('extra_row_' + idx)?.remove();
    }

    function changeExtraQty(idx, delta) {
        const input = document.getElementById('extra_qty_' + idx);
        input.value = Math.max(1, (parseFloat(input.value) || 1) + delta);
    }

    function collectExtraItems() {
        return Array.from(document.querySelectorAll('.extra-item-row')).map(row => {
            const idx = row.id.replace('extra_row_', '');
            return {
                name:  document.getElementById('extra_name_' + idx)?.value.trim() || '',
                sku:   document.getElementById('extra_sku_'  + idx)?.value.trim() || '',
                qty:   parseFloat(document.getElementById('extra_qty_' + idx)?.value) || 1,
                po_id: parseInt(document.getElementById('extra_po_'  + idx)?.value) || PO_IDS[0],
            };
        }).filter(e => e.name);
    }

    // ── Qty controls ─────────────────────────────────────────────────────────

    function changeQty(key, delta) {
        const item  = MERGED.find(i => i.key === key);
        const input = document.getElementById('qty_' + key);
        input.value = Math.max(0, (parseFloat(input.value) || 0) + delta);
        onQtyChange(key, item.total_ordered);
    }

    function setAllQty(useOrdered) {
        MERGED.forEach(item => {
            if (confirmedItems[item.key] !== undefined) return;
            document.getElementById('qty_' + item.key).value = useOrdered ? item.total_ordered : 0;
            onQtyChange(item.key, item.total_ordered);
        });
    }

    function onQtyChange(key, orderedQty) {
        const received = getQty(key);
        const row  = document.querySelector(`.item-row[data-key="${key}"]`);
        const disc = document.getElementById('disc_' + key);

        if (received === orderedQty) {
            disc.style.display = 'none';
            disc.innerHTML = '';
            delete discReasons[key];
            row.classList.remove('needs-reason', 'reason-ok-shortfall', 'reason-ok-surplus');
            return;
        }

        const isShortfall = received < orderedQty;
        row.classList.add('needs-reason');

        const options = isShortfall
            ? [{ value:'lipsa_stoc', label:'📦 Lipsă stoc furnizor' }, { value:'deteriorate', label:'🔴 Deteriorate / returnate' }]
            : [{ value:'accept_surplus', label:'✅ Accept marfa în plus' }, { value:'returnez_surplus', label:'↩️ Returnez ce e în plus' }];

        const current = discReasons[key];
        if (current) {
            const wasShortfall = ['lipsa_stoc','deteriorate'].includes(current);
            if (wasShortfall !== isShortfall) delete discReasons[key];
        }

        disc.style.display = 'block';
        disc.innerHTML = `<div class="disc-options">
            <div class="disc-label">Motivul diferenței:</div>
            ${options.map(o => `
                <button type="button" class="disc-btn${discReasons[key] === o.value ? (isShortfall ? ' selected-shortfall' : ' selected-surplus') : ''}"
                    data-value="${o.value}"
                    onclick="selectReason('${key}', '${o.value}', ${isShortfall})">
                    <div class="disc-dot"></div>${o.label}
                </button>`).join('')}
        </div>`;
    }

    function selectReason(key, value, isShortfall) {
        discReasons[key] = value;
        const row = document.querySelector(`.item-row[data-key="${key}"]`);

        if (value === 'returnez_surplus') {
            const item = MERGED.find(i => i.key === key);
            document.getElementById('qty_' + key).value = item.total_ordered;
            onQtyChange(key, item.total_ordered);
            return;
        }

        row.classList.remove('reason-ok-shortfall', 'reason-ok-surplus');
        row.classList.add(isShortfall ? 'reason-ok-shortfall' : 'reason-ok-surplus');

        document.getElementById('disc_' + key).querySelectorAll('.disc-btn').forEach(btn => {
            btn.classList.remove('selected-shortfall', 'selected-surplus');
            if (btn.dataset.value === value) btn.classList.add(isShortfall ? 'selected-shortfall' : 'selected-surplus');
        });
    }

    // ── Distribuire cantități pe sub-items ───────────────────────────────────

    function distributeItems() {
        return distributeItemsWithReasons({});
    }

    function distributeItemsWithReasons(allReasons) {
        const flat = [];
        MERGED.forEach(merged => {
            const received = getEffectiveQty(merged.key);
            const reason   = allReasons[merged.key] || null;
            // Poziția de pe factură = una per linie comasată → o punem pe toate sub-liniile acelui SKU
            const pos      = confirmedItems[merged.key]?.invoice_position ?? null;
            let remaining  = received;
            merged.sub_items.forEach((sub, idx) => {
                let give;
                if (idx === merged.sub_items.length - 1) {
                    give = Math.max(0, remaining);
                } else {
                    give = Math.min(remaining, sub.qty);
                }
                flat.push({ id: sub.id, qty: give, reason, invoice_position: pos });
                remaining -= give;
            });
        });
        return flat;
    }

    // ── Validare + Sumar ─────────────────────────────────────────────────────

    function handleSubmitClick() {
        let missing = false;
        MERGED.forEach(item => {
            if (confirmedItems[item.key] !== undefined) return;
            if (getQty(item.key) !== item.total_ordered && !discReasons[item.key]) {
                const row = document.getElementById('row_' + item.key);
                row.style.outline = '2px solid #f59e0b';
                row.scrollIntoView({ behavior:'smooth', block:'center' });
                setTimeout(() => row.style.outline = '', 2000);
                missing = true;
            }
        });
        document.querySelectorAll('.extra-item-row').forEach(row => {
            const idx = row.id.replace('extra_row_', '');
            const nameInput = document.getElementById('extra_name_' + idx);
            const skuInput  = document.getElementById('extra_sku_' + idx);
            if (!nameInput?.value.trim()) {
                nameInput.style.borderColor = '#dc2626';
                nameInput.focus();
                setTimeout(() => nameInput.style.borderColor = '', 2000);
                missing = true;
            }
            if (!skuInput?.value.trim()) {
                skuInput.style.borderColor = '#dc2626';
                if (!missing) skuInput.focus();
                setTimeout(() => skuInput.style.borderColor = '', 2000);
                missing = true;
            }
        });
        if (missing) return;

        const totalReceived = MERGED.reduce((sum, item) => sum + getEffectiveQty(item.key), 0)
            + collectExtraItems().reduce((sum, e) => sum + e.qty, 0);
        if (totalReceived === 0) {
            document.getElementById('error-msg').textContent = 'Nu poți confirma o recepție cu toate cantitățile 0.';
            document.getElementById('error-banner').style.display = 'block';
            window.scrollTo({ top:0, behavior:'smooth' });
            return;
        }

        // Cere poziția pentru liniile neconfirmate cu qty > 0
        _pendingPositionQueue = MERGED
            .filter(item => confirmedItems[item.key] === undefined && getQty(item.key) > 0)
            .map(item => item.key);

        if (_pendingPositionQueue.length > 0) {
            _bulkConfirmMode = true;
            processNextPendingPosition();
        } else {
            showSummary();
        }
    }

    function showSummary() {
        document.getElementById('summary-supplier').textContent = SUPPLIER;
        document.getElementById('summary-po-badges').innerHTML = PO_NUMBERS.map(n =>
            `<span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700">${n}</span>`
        ).join('');

        const itemsHtml = MERGED.map(item => {
            const received = getEffectiveQty(item.key);
            const ordered  = item.total_ordered;
            const diff     = received - ordered;
            const isOk     = diff === 0;
            const isConf   = confirmedItems[item.key] !== undefined;
            const color    = diff < 0 ? '#dc2626' : '#16a34a';
            const sign     = diff > 0 ? '+' : '';
            const reason   = getEffectiveReason(item.key);
            const reasonTxt= reason ? reasonLabels[reason] : '';
            const confBadge= isConf ? `<span style="font-size:11px; background:#dcfce7; color:#15803d; border-radius:6px; padding:2px 7px; font-weight:700; margin-left:6px">✓</span>` : '';
            const pos      = confirmedItems[item.key]?.invoice_position ?? null;
            const posBadge = pos ? `<span style="font-size:11px; background:#f1f5f9; color:#475569; border-radius:6px; padding:2px 7px; font-weight:700; margin-left:6px">poz. ${pos}</span>` : '';

            return `<div class="wh-card" style="margin-bottom:10px; border-left:4px solid ${isOk ? '#16a34a' : (diff < 0 ? '#dc2626' : '#f59e0b')}">
                <div style="display:flex; align-items:center; margin-bottom:4px">
                    <span style="font-weight:600; font-size:14px">${item.name}</span>${confBadge}${posBadge}
                </div>
                ${item.sku ? `<div style="font-size:12px; color:#64748b; margin-bottom:6px">SKU: ${item.sku}</div>` : ''}
                <div style="display:flex; align-items:baseline; flex-wrap:wrap; gap:4px; font-size:13px; color:#475569">
                    <span>Comandat: <strong>${ordered}</strong></span>
                    <span style="color:#94a3b8">→</span>
                    <span>Primit: <strong style="color:#1e293b">${received}</strong></span>
                    ${!isOk ? `<span style="font-size:12px; font-weight:700; color:${color}">${sign}${diff}</span>` : ''}
                </div>
                ${reasonTxt ? `<div style="font-size:12px; color:${color}; margin-top:4px">${reasonTxt}</div>` : ''}
            </div>`;
        }).join('');
        document.getElementById('summary-items').innerHTML = itemsHtml;

        const extras = collectExtraItems();
        if (extras.length) {
            document.getElementById('summary-extra-block').style.display = 'block';
            document.getElementById('summary-extra-items').innerHTML = extras.map(e => {
                const poNum = PO_NUMBERS[PO_IDS.indexOf(e.po_id)] || e.po_id;
                return `<div class="wh-card" style="margin-bottom:10px; border-left:4px solid #7c3aed">
                    <div style="font-weight:600; font-size:14px">${e.name}</div>
                    ${e.sku ? `<div style="font-size:12px; color:#64748b">SKU: ${e.sku}</div>` : ''}
                    <div style="font-size:13px; color:#475569; margin-top:4px">Cantitate: <strong>${e.qty}</strong> • ${poNum}</div>
                </div>`;
            }).join('');
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
        Object.entries(discReasons).forEach(([k, r]) => { if (!confirmedItems[k]) allReasons[k] = r; });
        Object.entries(confirmedItems).forEach(([k, d]) => { if (d.reason) allReasons[k] = d.reason; });

        const confirmBtn = document.getElementById('confirm-btn');
        if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Se trimite...'; }
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Se trimite...';

        const payload = {
            po_ids:         PO_IDS,
            items:          distributeItemsWithReasons(allReasons),
            extra_items:    collectExtraItems(),
            received_notes: document.getElementById('received_notes').value,
        };

        try {
            const res  = await fetch('/wh/batch', {
                method:  'POST',
                headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF },
                body:    JSON.stringify(payload),
            });
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
            hideSummary();
            document.getElementById('error-msg').textContent = e.message;
            document.getElementById('error-banner').style.display = 'block';
            btn.disabled  = false;
            btn.innerHTML = '✅ Confirmă recepția';
            if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = '✅ Confirmă'; }
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
        const clearBtn    = document.getElementById('search-clear');
        const searchInput = document.getElementById('item-search');
        const q = query.trim().toLowerCase();

        clearBtn.style.display    = q ? 'block' : 'none';
        searchInput.style.borderColor = q ? '#b91c1c' : '#e2e8f0';

        const list = document.getElementById('items-list');

        if (!q) {
            MERGED.forEach(item => {
                const row = document.getElementById('row_' + item.key);
                if (row) list.appendChild(row);
            });
            return;
        }

        const matched   = [];
        const unmatched = [];

        MERGED.forEach(item => {
            const row = document.getElementById('row_' + item.key);
            if (!row || confirmedItems[item.key] !== undefined) return;

            const haystack = (item.name + ' ' + (item.sku || '')).toLowerCase();
            if (haystack.includes(q)) {
                matched.push(row);
            } else {
                unmatched.push(row);
            }
        });

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

    // Enter pe input-ul de poziție = confirmă
    document.getElementById('pos-modal-input')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') confirmWithPosition();
    });

    // Init
    loadDraft();
</script>
@endsection
