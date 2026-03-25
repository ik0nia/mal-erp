<x-filament-panels::page>
    @php $stats = $this->getStats(); @endphp

    {{-- Stats cards --}}
    <div class="grid grid-cols-7 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center">
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Total propuneri</div>
        </div>
        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl shadow p-4 text-center">
            <div class="text-2xl font-bold text-amber-600">{{ number_format($stats['pending']) }}</div>
            <div class="text-xs text-gray-500 mt-1">În așteptare</div>
        </div>
        <div class="bg-green-50 dark:bg-green-900/20 rounded-xl shadow p-4 text-center">
            <div class="text-2xl font-bold text-green-600">{{ number_format($stats['approved']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Aprobate</div>
        </div>
        <div class="bg-red-50 dark:bg-red-900/20 rounded-xl shadow p-4 text-center">
            <div class="text-2xl font-bold text-red-600">{{ number_format($stats['rejected']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Respinse</div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700 rounded-xl shadow p-4 text-center">
            <div class="text-2xl font-bold text-gray-500">{{ number_format($stats['no_match']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Fără potrivire</div>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl shadow p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">
                {{ number_format($stats['products_with_cat']) }}
                <span class="text-sm font-normal text-gray-500">/ {{ number_format($stats['products_total']) }}</span>
            </div>
            <div class="text-xs text-gray-500 mt-1">Produse cu categorie</div>
        </div>
    </div>

    @if($stats['total'] === 0)
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center mb-6">
            <x-filament::icon icon="heroicon-o-sparkles" class="w-10 h-10 text-blue-400 mx-auto mb-3" />
            <p class="text-blue-700 dark:text-blue-300 font-medium">Nicio propunere încă.</p>
            <p class="text-blue-600 dark:text-blue-400 text-sm mt-1">
                Apasă <strong>„Pornește 15 agenți AI"</strong> din dreapta sus pentru a genera propunerile de categorii.
            </p>
        </div>
    @elseif($stats['pending'] === 0 && $stats['approved'] > 0)
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-4 text-center mb-6">
            <p class="text-green-700 dark:text-green-300 text-sm">
                ✓ Toate propunerile au fost procesate. Apasă <strong>„Aplică aprobate"</strong> pentru a actualiza produsele.
            </p>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
