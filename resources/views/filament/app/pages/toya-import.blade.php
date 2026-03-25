<x-filament-panels::page>

    @php $stats = $this->getStats(); @endphp

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 mb-6">

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center">
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total'], 0, '.', '') }}</div>
            <div class="text-sm text-gray-500 mt-1">Total importate</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center">
            <div class="text-3xl font-bold text-blue-600">{{ number_format($stats['withImage'], 0, '.', '') }}</div>
            <div class="text-sm text-gray-500 mt-1">Cu poză</div>
            @if($stats['total'] > 0)
                <div class="text-xs text-gray-400 mt-0.5">{{ round($stats['withImage'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center">
            <div class="text-3xl font-bold text-purple-600">{{ number_format($stats['withDesc'], 0, '.', '') }}</div>
            <div class="text-sm text-gray-500 mt-1">Cu descriere</div>
            @if($stats['total'] > 0)
                <div class="text-xs text-gray-400 mt-0.5">{{ round($stats['withDesc'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center">
            <div class="text-3xl font-bold text-orange-500">{{ number_format($stats['withCat'], 0, '.', '') }}</div>
            <div class="text-sm text-gray-500 mt-1">Cu categorie</div>
            @if($stats['total'] > 0)
                <div class="text-xs text-gray-400 mt-0.5">{{ round($stats['withCat'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 text-center">
            <div class="text-3xl font-bold text-green-600">{{ number_format($stats['readyToPub'], 0, '.', '') }}</div>
            <div class="text-sm text-gray-500 mt-1">Gata de publicat</div>
            @if($stats['total'] > 0)
                <div class="text-xs text-gray-400 mt-0.5">{{ round($stats['readyToPub'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

    </div>

    {{-- Comandă de import --}}
    @if($stats['total'] === 0)
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 mb-6 text-sm text-blue-700 dark:text-blue-300">
        <strong>Niciun produs importat încă.</strong>
        Rulează comanda artisan pentru a importa produsele Toya:<br>
        <code class="font-mono bg-blue-100 dark:bg-blue-900/40 px-2 py-0.5 rounded mt-1 inline-block">
            php artisan toya:import-products
        </code>
    </div>
    @else
    <div class="bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-xl p-4 mb-6 text-sm text-gray-600 dark:text-gray-400">
        <strong>Actualizare produse noi:</strong>
        <code class="font-mono bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded ml-1">
            php artisan toya:import-products
        </code>
        &nbsp;·&nbsp;
        <strong>Re-import complet:</strong>
        <code class="font-mono bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded ml-1">
            php artisan toya:import-products --force
        </code>
    </div>
    @endif

    {{ $this->table }}

</x-filament-panels::page>
