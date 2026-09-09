<x-filament-panels::page>

    <style>
    button.erp-stat { cursor:pointer; text-align:left; }
    button.erp-stat:focus { outline:none; }
    .erp-stat.sku-stat--active-warning { border-color:#fbbf24; background:#fffbeb; }
    .erp-stat.sku-stat--active-danger { border-color:#f87171; background:#fef2f2; }
    .erp-stat.sku-stat--active-info { border-color:#60a5fa; background:#eff6ff; }
    </style>

    {{-- Stats --}}
    <div class="erp-stat-grid">

        <button wire:click="setTab('placeholder')"
            class="erp-stat erp-stat--warning {{ $this->activeTab === 'placeholder' ? 'sku-stat--active-warning' : '' }}">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-exclamation-triangle" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Doar în WinMentor</p>
                <p class="erp-stat-value">{{ number_format($this->statPlaceholder, 0, '.', '') }}</p>
                <p class="erp-stat-sub">din care cu stoc: {{ number_format($this->statPlaceholderWithStock, 0, '.', '') }}</p>
            </div>
        </button>

        <button wire:click="setTab('no_sku')"
            class="erp-stat erp-stat--danger {{ $this->activeTab === 'no_sku' ? 'sku-stat--active-danger' : '' }}">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-tag" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Pe site, fără SKU</p>
                <p class="erp-stat-value">{{ number_format($this->statNoSku, 0, '.', '') }}</p>
                <p class="erp-stat-sub">nu pot fi legate de WinMentor</p>
            </div>
        </button>

        <button wire:click="setTab('no_mentor')"
            class="erp-stat erp-stat--info {{ $this->activeTab === 'no_mentor' ? 'sku-stat--active-info' : '' }}">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-link-slash" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Pe site, fără WinMentor</p>
                <p class="erp-stat-value">{{ number_format($this->statOnSiteNoMentor, 0, '.', '') }}</p>
                <p class="erp-stat-sub">SKU prezent, fără stoc din contabilitate</p>
            </div>
        </button>

        <div class="erp-stat">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-clipboard-document-list" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Total discrepanțe</p>
                <p class="erp-stat-value">
                    {{ number_format($this->statPlaceholder + $this->statNoSku + $this->statOnSiteNoMentor, 0, '.', '') }}
                </p>
                <p class="erp-stat-sub">produse cu date incomplete</p>
            </div>
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
