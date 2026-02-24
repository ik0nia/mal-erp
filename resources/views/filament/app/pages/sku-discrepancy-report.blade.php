<x-filament-panels::page>

    {{-- Stats --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">

        <button
            wire:click="setTab('placeholder')"
            class="rounded-xl border p-4 text-left transition hover:shadow-md focus:outline-none
                {{ $this->activeTab === 'placeholder' ? 'border-warning-400 bg-warning-50 dark:bg-warning-950/20' : 'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' }}"
        >
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Doar în WinMentor</div>
            <div class="mt-1 text-3xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($this->statPlaceholder) }}</div>
            <div class="mt-1 text-xs text-gray-400">din care cu stoc: {{ number_format($this->statPlaceholderWithStock) }}</div>
        </button>

        <button
            wire:click="setTab('no_sku')"
            class="rounded-xl border p-4 text-left transition hover:shadow-md focus:outline-none
                {{ $this->activeTab === 'no_sku' ? 'border-danger-400 bg-danger-50 dark:bg-danger-950/20' : 'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' }}"
        >
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Pe site, fără SKU</div>
            <div class="mt-1 text-3xl font-bold text-danger-600 dark:text-danger-400">{{ number_format($this->statNoSku) }}</div>
            <div class="mt-1 text-xs text-gray-400">nu pot fi legate de WinMentor</div>
        </button>

        <button
            wire:click="setTab('no_mentor')"
            class="rounded-xl border p-4 text-left transition hover:shadow-md focus:outline-none
                {{ $this->activeTab === 'no_mentor' ? 'border-info-400 bg-info-50 dark:bg-info-950/20' : 'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' }}"
        >
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Pe site, fără WinMentor</div>
            <div class="mt-1 text-3xl font-bold text-info-600 dark:text-info-400">{{ number_format($this->statOnSiteNoMentor) }}</div>
            <div class="mt-1 text-xs text-gray-400">SKU prezent, fără stoc din contabilitate</div>
        </button>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total discrepanțe</div>
            <div class="mt-1 text-3xl font-bold text-gray-800 dark:text-gray-100">
                {{ number_format($this->statPlaceholder + $this->statNoSku + $this->statOnSiteNoMentor) }}
            </div>
            <div class="mt-1 text-xs text-gray-400">produse cu date incomplete</div>
        </div>

    </div>

    {{-- Tab label --}}
    <div class="text-sm text-gray-500 dark:text-gray-400 -mb-2">
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
