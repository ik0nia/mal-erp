<x-filament-panels::page>
    <div class="flex flex-col items-center justify-center py-20 text-center">

        <div class="mb-6 flex items-center justify-center w-24 h-24 rounded-full
            {{ $code === 403 ? 'bg-danger-50 dark:bg-danger-950' : 'bg-warning-50 dark:bg-warning-950' }}">
            @if($code === 403)
                <x-heroicon-o-lock-closed class="w-12 h-12 text-danger-500" />
            @elseif($code === 404)
                <x-heroicon-o-magnifying-glass class="w-12 h-12 text-warning-500" />
            @else
                <x-heroicon-o-exclamation-triangle class="w-12 h-12 text-warning-500" />
            @endif
        </div>

        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-3">
            @if($code === 403)
                Nu ai acces la această pagină
            @elseif($code === 404)
                Pagina nu a fost găsită
            @else
                A apărut o eroare
            @endif
        </h1>

        <p class="text-gray-500 dark:text-gray-400 text-base max-w-md mb-8">
            @if($code === 403)
                Dacă crezi că este o greșeală, contactează un administrator.
            @elseif($code === 404)
                Pagina pe care o cauți nu există sau a fost mutată.
            @else
                Te rugăm să încerci din nou sau contactează un administrator.
            @endif
        </p>

        <a
            href="{{ url('/') }}"
            class="fi-btn fi-btn-size-md inline-grid grid-flow-col items-center gap-1.5 font-semibold rounded-lg px-4 py-2
                bg-primary-600 hover:bg-primary-500 text-white shadow-sm transition"
        >
            <x-heroicon-o-home class="w-4 h-4" />
            Înapoi la dashboard
        </a>

    </div>
</x-filament-panels::page>
