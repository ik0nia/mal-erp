<x-filament-panels::page>

    {{-- Stat pills --}}
    <div class="flex flex-wrap gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs dark:border-white/10 dark:bg-gray-900">
            <span class="text-gray-500 dark:text-gray-400">Total fără furnizor:</span>
            <span class="font-bold text-gray-800 dark:text-gray-100">{{ number_format($this->statTotal) }}</span>
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-warning-200 bg-warning-50 px-3 py-1 text-xs dark:border-warning-400/20 dark:bg-warning-950/20">
            <span class="text-warning-700 dark:text-warning-300">Placeholder:</span>
            <span class="font-bold text-warning-700 dark:text-warning-300">{{ number_format($this->statPlaceholder) }}</span>
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-success-200 bg-success-50 px-3 py-1 text-xs dark:border-success-400/20 dark:bg-success-950/20">
            <span class="text-success-700 dark:text-success-300">Cu stoc > 0:</span>
            <span class="font-bold text-success-700 dark:text-success-300">{{ number_format($this->statWithStock) }}</span>
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-primary-200 bg-primary-50 px-3 py-1 text-xs dark:border-primary-400/20 dark:bg-primary-950/20">
            <span class="text-primary-700 dark:text-primary-300">Cu brand identificat:</span>
            <span class="font-bold text-primary-700 dark:text-primary-300">{{ number_format($this->statWithBrand) }}</span>
        </span>
    </div>

    {{ $this->table }}

</x-filament-panels::page>
