<x-filament-widgets::widget class="fi-wi-table">
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

        {{-- Header fix, în afara zonei de scroll --}}
        <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10 sm:px-6 sm:py-4">
            <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                Ultimele modificări de prețuri
            </h3>
        </div>

        {{-- Conținut scrollabil --}}
        <div class="overflow-y-auto" style="max-height: 20rem;">
            {{ $this->table }}
        </div>

    </div>
</x-filament-widgets::widget>
