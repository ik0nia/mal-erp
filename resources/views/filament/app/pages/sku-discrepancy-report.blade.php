<x-filament-panels::page>

    <style>
    .sku-stats { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; }
    @@media(min-width:768px){ .sku-stats { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
    .sku-stat { border-radius:0.75rem; border:1px solid #e5e7eb; background:#fff; padding:1rem; text-align:left; cursor:pointer; transition:box-shadow 0.15s; }
    .sku-stat:hover { box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); }
    .sku-stat:focus { outline:none; }
    .sku-stat--active-warning { border-color:#fbbf24; background:#fffbeb; }
    .sku-stat--active-danger { border-color:#f87171; background:#fef2f2; }
    .sku-stat--active-info { border-color:#60a5fa; background:#eff6ff; }
    .sku-stat-label { font-size:0.75rem; font-weight:500; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; }
    .sku-stat-value { margin-top:0.25rem; font-size:1.875rem; font-weight:700; }
    .sku-stat-value--warning { color:#d97706; }
    .sku-stat-value--danger { color:#dc2626; }
    .sku-stat-value--info { color:#2563eb; }
    .sku-stat-value--gray { color:#1f2937; }
    .sku-stat-sub { margin-top:0.25rem; font-size:0.75rem; color:#9ca3af; }
    .sku-stat--static { cursor:default; }
    .sku-stat--static:hover { box-shadow:none; }
    </style>

    {{-- Stats --}}
    <div class="sku-stats">

        <button wire:click="setTab('placeholder')"
            class="sku-stat {{ $this->activeTab === 'placeholder' ? 'sku-stat--active-warning' : '' }}">
            <div class="sku-stat-label">Doar în WinMentor</div>
            <div class="sku-stat-value sku-stat-value--warning">{{ number_format($this->statPlaceholder, 0, '.', '') }}</div>
            <div class="sku-stat-sub">din care cu stoc: {{ number_format($this->statPlaceholderWithStock, 0, '.', '') }}</div>
        </button>

        <button wire:click="setTab('no_sku')"
            class="sku-stat {{ $this->activeTab === 'no_sku' ? 'sku-stat--active-danger' : '' }}">
            <div class="sku-stat-label">Pe site, fără SKU</div>
            <div class="sku-stat-value sku-stat-value--danger">{{ number_format($this->statNoSku, 0, '.', '') }}</div>
            <div class="sku-stat-sub">nu pot fi legate de WinMentor</div>
        </button>

        <button wire:click="setTab('no_mentor')"
            class="sku-stat {{ $this->activeTab === 'no_mentor' ? 'sku-stat--active-info' : '' }}">
            <div class="sku-stat-label">Pe site, fără WinMentor</div>
            <div class="sku-stat-value sku-stat-value--info">{{ number_format($this->statOnSiteNoMentor, 0, '.', '') }}</div>
            <div class="sku-stat-sub">SKU prezent, fără stoc din contabilitate</div>
        </button>

        <div class="sku-stat sku-stat--static">
            <div class="sku-stat-label">Total discrepanțe</div>
            <div class="sku-stat-value sku-stat-value--gray">
                {{ number_format($this->statPlaceholder + $this->statNoSku + $this->statOnSiteNoMentor, 0, '.', '') }}
            </div>
            <div class="sku-stat-sub">produse cu date incomplete</div>
        </div>

    </div>

    {{-- Tab label --}}
    <div style="font-size:0.875rem; color:#6b7280; margin-bottom:-0.5rem;">
        @if($this->activeTab === 'placeholder')
            Produse prezente în WinMentor (contabilitate) dar care <strong>nu există pe site</strong>.
        @elseif($this->activeTab === 'no_sku')
            Produse pe site care <strong>nu au SKU completat</strong> — nu pot fi legate automat de WinMentor.
        @else
            Produse pe site cu SKU completat, dar <strong>fără niciun import din WinMentor</strong>.
        @endif
    </div>

    {{-- Table --}}
    {{ $this->table }}

</x-filament-panels::page>
