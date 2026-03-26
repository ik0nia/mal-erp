<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
<div
    x-data="{
        items: $wire.entangle('purchaseItems'),
        ui:    [],

        rez: {
            show:            false,
            rowIndex:        null,
            tab:             'customer',
            customerSearch:  '',
            customerResults: [],
            customerLoading: false,
            offerSearch:     '',
            offerResults:    [],
            offerLoading:    false,
        },

        init() {
            this.$watch('items', val => {
                while (this.ui.length < val.length) {
                    const item = val[this.ui.length];
                    this.ui.push({ search: item ? (item.product_label || '') : '', results: [], open: false, loading: false, showNotes: !!(item && item.notes) });
                }
            });
            if (!Array.isArray(this.items) || this.items.length === 0) this.items = [this.emptyRow()];
            this.ui = this.items.map(item => ({ search: item.product_label || '', results: [], open: false, loading: false, showNotes: !!(item.notes) }));
            this.$nextTick(() => this.$el.querySelector('.erp-prod')?.focus());
        },

        emptyRow() {
            return { id: null, woo_product_id: null, product_label: '', quantity: 1, needed_by: '',
                     is_urgent: false, is_reserved: false,
                     customer_id: null, customer_label: '', offer_id: null, offer_label: '', notes: '' };
        },

        addRow() {
            const newItems = [...this.items, this.emptyRow()];
            this.ui.push({ search: '', results: [], open: false, loading: false, showNotes: false });
            this.items = newItems;
            const idx = newItems.length - 1;
            this.$nextTick(() => this.$el.querySelectorAll('.erp-prod')[idx]?.focus());
        },

        removeRow(index) {
            const newItems = this.items.filter((_, i) => i !== index);
            const newUi   = this.ui.filter((_, i) => i !== index);
            if (newItems.length === 0) {
                this.items = [this.emptyRow()];
                this.ui    = [{ search: '', results: [], open: false, loading: false, showNotes: false }];
            } else {
                this.items = newItems; // x-for se scurtează, ui e încă lung — fără crash
                this.ui    = newUi;   // acum ui se aliniază
            }
        },

        async doSearch(index, q) {
            this.ui[index].search = q;
            if (q.length < 2) { this.ui[index].results = []; this.ui[index].open = false; return; }
            this.ui[index].loading = true;
            try {
                const r = await fetch('/achizirii/products-search?q=' + encodeURIComponent(q));
                if (r.ok) { const d = await r.json(); this.ui[index].results = d; this.ui[index].open = d.length > 0; }
            } catch(e) {} finally { this.ui[index].loading = false; }
        },

        pick(index, product) {
            this.items = this.items.map((item, i) => i !== index ? item : { ...item, woo_product_id: product.id, product_label: product.label });
            this.ui[index].search = product.label; this.ui[index].results = []; this.ui[index].open = false;
            this.$nextTick(() => this.$el.querySelectorAll('.erp-qty')[index]?.focus());
        },

        setField(index, field, value) {
            this.items = this.items.map((item, i) => i === index ? { ...item, [field]: value } : item);
        },

onRowEscape(index) {
            const item = this.items[index];
            if (!item.woo_product_id && !item.notes && item.quantity === 1 && !item.needed_by && this.items.length > 1) {
                this.removeRow(index);
                this.$nextTick(() => { const i = Math.min(index, this.items.length - 1); this.$el.querySelectorAll('.erp-prod')[i]?.focus(); });
            }
        },

        toggleNotes(index) {
            this.ui[index].showNotes = !this.ui[index].showNotes;
            if (this.ui[index].showNotes) this.$nextTick(() => this.$el.querySelectorAll('.erp-notes')[index]?.focus());
        },

        openRezervat(index) {
            this.rez.rowIndex = index;
            this.rez.customerSearch = this.items[index].customer_label || '';
            this.rez.customerResults = [];
            this.rez.offerSearch = this.items[index].offer_label || '';
            this.rez.offerResults = [];
            // dacă produsul e rezervat pe ofertă, deschidem direct tab-ul Ofertă
            this.rez.tab = this.items[index].offer_id ? 'offer' : 'customer';
            this.rez.show = true;
            // preîncărcăm ultimele 5 oferte
            this.searchOffers(this.rez.offerSearch);
        },

        clearRezervat(index) {
            this.items = this.items.map((item, i) => i !== index ? item : { ...item, is_reserved: false, customer_id: null, customer_label: '', offer_id: null, offer_label: '' });
        },

        confirmRezervat(type, id, label) {
            const idx = this.rez.rowIndex;
            if (type === 'customer') {
                this.items = this.items.map((item, i) => i !== idx ? item : { ...item, is_reserved: true, customer_id: id, customer_label: label, offer_id: null, offer_label: '' });
            } else {
                this.items = this.items.map((item, i) => i !== idx ? item : { ...item, is_reserved: true, offer_id: id, offer_label: label, customer_id: null, customer_label: '' });
            }
            this.rez.show = false;
        },

        async searchCustomers(q) {
            this.rez.customerSearch = q;
            if (q.length < 2) { this.rez.customerResults = []; return; }
            this.rez.customerLoading = true;
            try { const r = await fetch('/achizirii/customers-search?q=' + encodeURIComponent(q)); if (r.ok) this.rez.customerResults = await r.json(); }
            catch(e) {} finally { this.rez.customerLoading = false; }
        },

        async searchOffers(q) {
            this.rez.offerSearch = q;
            this.rez.offerLoading = true;
            try { const r = await fetch('/achizirii/offers-search?q=' + encodeURIComponent(q)); if (r.ok) this.rez.offerResults = await r.json(); }
            catch(e) {} finally { this.rez.offerLoading = false; }
        },

        rezTooltip(item) {
            if (item.customer_label) return 'Rezervat: ' + item.customer_label;
            if (item.offer_label)    return 'Rezervat: ' + item.offer_label;
            return 'Rezervat — click pentru a schimba';
        },
    }"
    style="min-width:0"
>
    {{-- Header --}}
    <div class="erp-pr-row" style="padding-bottom:8px;margin-bottom:2px;border-bottom:2px solid #e5e7eb;min-width:620px">
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em">Produs</div>
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;text-align:center">Cant.</div>
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em">Termen</div>
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;text-align:center">Urgent</div>
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;text-align:center">Rezervat</div>
        <div></div>
        <div></div>
    </div>

    {{-- Rânduri --}}
    <template x-for="(item, index) in items" :key="index">
        <div class="erp-pr-item" style="min-width:620px">

            <div class="erp-pr-row" style="padding-top:4px;padding-bottom:4px;border-bottom:1px solid #f3f4f6">

                {{-- Produs --}}
                <div style="position:relative;min-width:0">
                    <input type="text"
                        class="erp-prod"
                        style="width:100%;font-size:14px;border:1px solid #d1d5db;border-radius:8px;padding:8px 12px;background:#fff;color:#111827;outline:none;box-shadow:0 1px 2px rgba(0,0,0,0.05)"
                        placeholder="Caută produs…" autocomplete="off"
                        :value="ui[index]?.search"
                        @input.debounce.300ms="doSearch(index, $event.target.value)"
                        @keydown.escape.stop="ui[index]?.open ? (ui[index].open = false) : onRowEscape(index)"
                        @focus="ui[index]?.results?.length && (ui[index].open = true)"
                        @blur="setTimeout(() => { if(ui[index]) { ui[index].open = false; ui[index].search = item.woo_product_id ? item.product_label : ''; } }, 200)"
                    />
                    <div x-show="ui[index]?.loading" style="position:absolute;right:10px;top:10px;pointer-events:none">
                        <svg style="animation:spin 1s linear infinite;width:16px;height:16px;color:#9ca3af" fill="none" viewBox="0 0 24 24">
                            <circle style="opacity:0.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path style="opacity:0.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                    </div>
                    <div x-show="ui[index]?.open" @click.outside="if(ui[index]) ui[index].open = false"
                        style="display:none;position:absolute;z-index:50;left:0;top:100%;margin-top:2px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);overflow-y:auto;max-height:360px;min-width:750px">
                        <template x-for="res in ui[index]?.results ?? []" :key="res.id">
                            <div @mousedown.prevent="pick(index, res)"
                                style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;font-size:14px;cursor:pointer;color:#1f2937;border-bottom:1px solid #f3f4f6"
                                @mouseover="$el.style.background='#eef2ff'"
                                @mouseout="$el.style.background=''">
                                <span x-text="res.label" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:16px"></span>
                                <span x-show="res.price" x-text="res.price ? parseFloat(res.price).toFixed(2) + ' lei' : ''"
                                    style="flex-shrink:0;font-size:12px;font-weight:600;color:#ef4444"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Cantitate --}}
                <input type="number"
                    class="erp-qty"
                    style="width:100%;font-size:14px;text-align:center;border:1px solid #d1d5db;border-radius:8px;padding:8px 4px;background:#fff;color:#111827;outline:none;box-shadow:0 1px 2px rgba(0,0,0,0.05)"
                    min="0.001" step="any"
                    :value="item.quantity"
                    @change="setField(index, 'quantity', parseFloat($event.target.value) || 1)"
                    @keydown.tab="if (index === items.length - 1) { $event.preventDefault(); addRow(); }"
                    @keydown.escape="onRowEscape(index)"
                />

                {{-- Termen — tabindex=-1 ca Tab să sară peste el --}}
                <input type="date"
                    tabindex="-1"
                    style="width:100%;font-size:14px;border:1px solid #d1d5db;border-radius:8px;padding:8px;background:#fff;color:#111827;outline:none;box-shadow:0 1px 2px rgba(0,0,0,0.05)"
                    :value="item.needed_by"
                    @change="setField(index, 'needed_by', $event.target.value)"
                    @keydown.escape="onRowEscape(index)"
                />

                {{-- Urgent --}}
                <button type="button" tabindex="-1"
                    class="erp-toggle-btn"
                    style="width:100%;height:32px;border-radius:8px;border:1px solid;font-size:12px;font-weight:500;transition:all 150ms;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer"
                    :style="item.is_urgent
                        ? 'width:100%;height:32px;border-radius:8px;border:1px solid;font-size:12px;font-weight:500;transition:all 150ms;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer;background:#ef4444;color:#fff;border-color:#ef4444;'
                        : 'width:100%;height:32px;border-radius:8px;border:1px solid;font-size:12px;font-weight:500;transition:all 150ms;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer;background:#fff;color:#9ca3af;border-color:#d1d5db;'"
                    @click="setField(index, 'is_urgent', !item.is_urgent)"
                    :title="item.is_urgent ? 'Urgent — dezactivează' : 'Marchează urgent'">
                    <svg style="width:12px;height:12px;flex-shrink:0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span>Urgent</span>
                </button>

                {{-- Rezervat --}}
                <div style="display:flex;flex-direction:column;align-items:stretch;gap:2px">
                    <button type="button" tabindex="-1"
                        class="erp-toggle-btn"
                        style="width:100%;height:32px;border-radius:8px;border:1px solid;font-size:12px;font-weight:500;transition:all 150ms;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer"
                        :style="item.is_reserved
                            ? 'width:100%;height:32px;border-radius:8px;border:1px solid;font-size:12px;font-weight:500;transition:all 150ms;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer;background:#f59e0b;color:#fff;border-color:#f59e0b;'
                            : 'width:100%;height:32px;border-radius:8px;border:1px solid;font-size:12px;font-weight:500;transition:all 150ms;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer;background:#fff;color:#9ca3af;border-color:#d1d5db;'"
                        @click="openRezervat(index)"
                        :title="item.is_reserved ? rezTooltip(item) : 'Marchează rezervat'">
                        <svg style="width:12px;height:12px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                        </svg>
                        <span>Rezervat</span>
                    </button>
                    <div x-show="item.is_reserved" style="display:none;align-items:center;gap:2px" x-bind:style="item.is_reserved ? 'display:flex;align-items:center;gap:2px' : 'display:none'">
                        <span style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;line-height:1;color:#d97706;max-width:62px"
                              :text="item.customer_label || item.offer_label"
                              x-text="item.customer_label || item.offer_label || '—'"></span>
                        <button type="button" @click.stop="clearRezervat(index)"
                            style="flex-shrink:0;color:#9ca3af;transition:color 150ms;line-height:1;font-size:10px;background:none;border:none;cursor:pointer"
                            onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#9ca3af'"
                            title="Elimină rezervarea">✕</button>
                    </div>
                </div>

                {{-- Notițe --}}
                <button type="button" tabindex="-1" @click="toggleNotes(index)"
                    style="width:100%;height:32px;border-radius:8px;border:1px solid transparent;display:flex;align-items:center;justify-content:center;transition:color 150ms;cursor:pointer;background:none"
                    :style="(item.notes && item.notes.length) || ui[index]?.showNotes
                        ? 'width:100%;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:color 150ms;cursor:pointer;color:#f59e0b;background:#fffbeb;border:1px solid #fcd34d;'
                        : 'width:100%;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:color 150ms;cursor:pointer;color:#9ca3af;border:1px solid transparent;background:none;'"
                    :class="!((item.notes && item.notes.length) || ui[index]?.showNotes) ? 'erp-act-btn' : ''"
                    title="Notițe">
                    <svg style="width:16px;height:16px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                    </svg>
                </button>

                {{-- Șterge --}}
                <button type="button" tabindex="-1" @click="removeRow(index)"
                    class="erp-act-btn"
                    style="width:100%;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#9ca3af;transition:color 150ms;cursor:pointer;background:none;border:none"
                    onmouseover="this.style.color='#ef4444';this.style.background='#fef2f2'" onmouseout="this.style.color='#9ca3af';this.style.background='none'"
                    title="Șterge">
                    <svg style="width:14px;height:14px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Notițe expandabile --}}
            <div x-show="ui[index]?.showNotes" style="display:none;padding-top:4px;padding-bottom:4px;border-bottom:1px solid #f3f4f6">
                <div style="display:flex;align-items:center;gap:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 12px">
                    <svg style="width:14px;height:14px;color:#fbbf24;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                    </svg>
                    <input type="text"
                        class="erp-notes"
                        style="flex:1;font-size:14px;background:transparent;border:none;outline:none;color:#1f2937"
                        placeholder="Notițe pentru acest produs…"
                        :value="item.notes"
                        @change="setField(index, 'notes', $event.target.value)"
                    />
                    <button type="button" @click="toggleNotes(index)" style="color:#9ca3af;transition:color 150ms;flex-shrink:0;background:none;border:none;cursor:pointer"
                        onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">
                        <svg style="width:14px;height:14px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </template>

    {{-- Adaugă linie --}}
    <button type="button" @click="addRow()"
        style="margin-top:8px;display:flex;align-items:center;gap:6px;font-size:14px;color:#8B1A1A;font-weight:500;transition:color 150ms;background:none;border:none;cursor:pointer">
        <svg style="width:16px;height:16px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Adaugă produs
    </button>

    <p style="margin-top:6px;font-size:12px;color:#9ca3af">
        Tab din cantitate → linie nouă &nbsp;·&nbsp; Esc pe linie goală → șterge linia
    </p>

    {{-- Modal rezervare — teleportat pe body ca să nu fie clipat --}}
    <template x-teleport="body">
        {{-- Overlay: x-show controlează display (block/none). Position:fixed acoperă tot ecranul. --}}
        <div
            x-show="rez.show"
            @keydown.escape.window="rez.show = false"
            @click.self="rez.show = false"
            style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);display:none"
        >
            {{-- Wrapper flex pentru centrare — mereu flex când overlay-ul e vizibil --}}
            <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;padding:16px;box-sizing:border-box">
            <div
                style="background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:420px;overflow:hidden"
            >
                {{-- Header --}}
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb">
                    <span style="font-size:15px;font-weight:600;color:#111827;display:flex;align-items:center;gap:8px">
                        <svg style="width:16px;height:16px;color:#f59e0b;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/>
                        </svg>
                        Rezervă produs
                    </span>
                    <button type="button" @click="rez.show = false"
                        style="color:#9ca3af;background:none;border:none;cursor:pointer;padding:4px;border-radius:6px"
                        onmouseover="this.style.color='#374151'" onmouseout="this.style.color='#9ca3af'">
                        <svg style="width:18px;height:18px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- Tabs --}}
                <div style="display:flex;border-bottom:1px solid #e5e7eb">
                    <button type="button" @click="rez.tab = 'customer'"
                        :style="rez.tab === 'customer'
                            ? 'flex:1;padding:12px 16px;font-size:13px;font-weight:500;color:#6366f1;border-bottom:2px solid #6366f1;background:none;border-top:none;border-left:none;border-right:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px'
                            : 'flex:1;padding:12px 16px;font-size:13px;color:#6b7280;border:none;background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px'">
                        <svg style="width:15px;height:15px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Client
                    </button>
                    <button type="button" @click="rez.tab = 'offer'"
                        :style="rez.tab === 'offer'
                            ? 'flex:1;padding:12px 16px;font-size:13px;font-weight:500;color:#6366f1;border-bottom:2px solid #6366f1;background:none;border-top:none;border-left:none;border-right:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px'
                            : 'flex:1;padding:12px 16px;font-size:13px;color:#6b7280;border:none;background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px'">
                        <svg style="width:15px;height:15px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Ofertă
                    </button>
                </div>

                {{-- Tab Client --}}
                <div x-show="rez.tab === 'customer'" style="padding:16px;display:none">
                    <div style="position:relative;margin-bottom:10px">
                        <input type="text"
                            :value="rez.customerSearch"
                            @input.debounce.300ms="searchCustomers($event.target.value)"
                            @keydown.escape="rez.show = false"
                            autocomplete="off"
                            placeholder="Caută client după nume sau telefon…"
                            style="width:100%;padding:9px 36px 9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;outline:none;box-sizing:border-box;color:#111827"
                            onfocus="this.style.borderColor='#6366f1';this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.1)'"
                            onblur="this.style.borderColor='#d1d5db';this.style.boxShadow='none'"
                        />
                        <span x-show="rez.customerLoading" style="position:absolute;right:10px;top:50%;transform:translateY(-50%)">
                            <svg style="width:14px;height:14px;color:#9ca3af;animation:spin 1s linear infinite" fill="none" viewBox="0 0 24 24">
                                <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                        </span>
                    </div>
                    <div style="max-height:200px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px">
                        <template x-for="c in rez.customerResults" :key="c.id">
                            <button type="button" @click="confirmRezervat('customer', c.id, c.label)"
                                style="width:100%;text-align:left;padding:10px 14px;font-size:13px;color:#1f2937;border:none;border-bottom:1px solid #f3f4f6;background:#fff;cursor:pointer;display:flex;align-items:center;gap:8px"
                                onmouseover="this.style.background='#eef2ff'" onmouseout="this.style.background='#fff'">
                                <svg style="width:13px;height:13px;color:#9ca3af;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <span x-text="c.label"></span>
                            </button>
                        </template>
                        <div x-show="!rez.customerLoading && rez.customerResults.length === 0 && rez.customerSearch.length >= 2"
                            style="padding:16px;text-align:center;font-size:13px;color:#9ca3af;display:none">
                            Niciun client găsit.
                        </div>
                        <div x-show="rez.customerSearch.length < 2"
                            style="padding:16px;text-align:center;font-size:13px;color:#9ca3af">
                            Introdu cel puțin 2 caractere…
                        </div>
                    </div>
                </div>

                {{-- Tab Ofertă --}}
                <div x-show="rez.tab === 'offer'" style="padding:16px;display:none">
                    <div x-show="rez.offerSearch.length < 2" style="font-size:11px;color:#9ca3af;margin-bottom:6px;display:none">
                        Ofertele tale recente:
                    </div>
                    <div style="position:relative;margin-bottom:10px">
                        <input type="text"
                            :value="rez.offerSearch"
                            @input.debounce.300ms="searchOffers($event.target.value)"
                            @keydown.escape="rez.show = false"
                            autocomplete="off"
                            placeholder="Caută ofertă după număr sau client…"
                            style="width:100%;padding:9px 36px 9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;outline:none;box-sizing:border-box;color:#111827"
                            onfocus="this.style.borderColor='#6366f1';this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.1)'"
                            onblur="this.style.borderColor='#d1d5db';this.style.boxShadow='none'"
                        />
                        <span x-show="rez.offerLoading" style="position:absolute;right:10px;top:50%;transform:translateY(-50%)">
                            <svg style="width:14px;height:14px;color:#9ca3af;animation:spin 1s linear infinite" fill="none" viewBox="0 0 24 24">
                                <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                        </span>
                    </div>
                    <div style="max-height:200px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px">
                        <template x-for="o in rez.offerResults" :key="o.id">
                            <button type="button" @click="confirmRezervat('offer', o.id, o.label)"
                                style="width:100%;text-align:left;padding:10px 14px;font-size:13px;color:#1f2937;border:none;border-bottom:1px solid #f3f4f6;background:#fff;cursor:pointer;display:flex;align-items:center;gap:8px"
                                onmouseover="this.style.background='#eef2ff'" onmouseout="this.style.background='#fff'">
                                <svg style="width:13px;height:13px;color:#9ca3af;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span x-text="o.label"></span>
                            </button>
                        </template>
                        <div x-show="!rez.offerLoading && rez.offerResults.length === 0"
                            style="padding:16px;text-align:center;font-size:13px;color:#9ca3af;display:none">
                            Nicio ofertă găsită.
                        </div>
                    </div>
                </div>

                {{-- Footer --}}
                <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between">
                    <template x-if="rez.rowIndex !== null && items[rez.rowIndex] && items[rez.rowIndex].is_reserved">
                        <button type="button" @click="clearRezervat(rez.rowIndex); rez.show = false"
                            style="font-size:13px;color:#ef4444;background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px"
                            onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='none'">
                            Elimină rezervarea
                        </button>
                    </template>
                    <template x-if="!(rez.rowIndex !== null && items[rez.rowIndex] && items[rez.rowIndex].is_reserved)">
                        <span></span>
                    </template>
                    <button type="button" @click="rez.show = false"
                        style="font-size:13px;color:#6b7280;background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px"
                        onmouseover="this.style.color='#374151'" onmouseout="this.style.color='#6b7280'">
                        Anulează
                    </button>
                </div>
            </div>
            </div>{{-- /flex centering wrapper --}}
        </div>{{-- /overlay --}}
    </template>

</div>
</x-dynamic-component>
