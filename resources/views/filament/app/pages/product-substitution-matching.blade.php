<x-filament-panels::page>

    @php $stats = $this->getStats(); @endphp

    {{-- Stat cards --}}
    <div class="grid grid-cols-7 gap-3 mb-6">

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['totalSource'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">Produse totale</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-gray-400">{{ number_format($stats['unprocessed'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">Neprocesate</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-yellow-500">{{ number_format($stats['pending'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">În așteptare</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-green-600">{{ number_format($stats['approved'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">Aprobate</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-red-500">{{ number_format($stats['rejected'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">Respinse</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-gray-400">{{ number_format($stats['noMatch'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">Fără match</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 text-center">
            <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total'], 0, '.', '') }}</div>
            <div class="text-xs text-gray-500 mt-1">Procesate total</div>
        </div>

    </div>

    @if($stats['total'] === 0)
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 mb-6 text-sm text-blue-700 dark:text-blue-300">
        <strong>Nicio propunere generată încă.</strong>
        Apasă <strong>Pornește agenți AI</strong> pentru a începe analiza.
        Agenții vor căuta automat echivalente Toya pentru cele {{ number_format($stats['totalSource'], 0, '.', '') }} produse existente.
    </div>
    @endif

    {{ $this->table }}

</x-filament-panels::page>
