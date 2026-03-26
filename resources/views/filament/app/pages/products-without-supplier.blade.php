<x-filament-panels::page>

    {{-- Stat pills --}}
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
        <span style="display: inline-flex; align-items: center; gap: 0.375rem; border-radius: 9999px; border: 1px solid #e5e7eb; background: #fff; padding: 0.25rem 0.75rem; font-size: 0.75rem; line-height: 1rem;">
            <span style="color: #6b7280;">Total fără furnizor:</span>
            <span style="font-weight: 700; color: #1f2937;">{{ number_format($this->statTotal, 0, '.', '') }}</span>
        </span>
        <span style="display: inline-flex; align-items: center; gap: 0.375rem; border-radius: 9999px; border: 1px solid #fde68a; background: #fffbeb; padding: 0.25rem 0.75rem; font-size: 0.75rem; line-height: 1rem;">
            <span style="color: #b45309;">Placeholder:</span>
            <span style="font-weight: 700; color: #b45309;">{{ number_format($this->statPlaceholder, 0, '.', '') }}</span>
        </span>
        <span style="display: inline-flex; align-items: center; gap: 0.375rem; border-radius: 9999px; border: 1px solid #bbf7d0; background: #f0fdf4; padding: 0.25rem 0.75rem; font-size: 0.75rem; line-height: 1rem;">
            <span style="color: #15803d;">Cu stoc > 0:</span>
            <span style="font-weight: 700; color: #15803d;">{{ number_format($this->statWithStock, 0, '.', '') }}</span>
        </span>
        <span style="display: inline-flex; align-items: center; gap: 0.375rem; border-radius: 9999px; border: 1px solid #e8c4c4; background: #fdf2f2; padding: 0.25rem 0.75rem; font-size: 0.75rem; line-height: 1rem;">
            <span style="color: #8B1A1A;">Cu brand identificat:</span>
            <span style="font-weight: 700; color: #8B1A1A;">{{ number_format($this->statWithBrand, 0, '.', '') }}</span>
        </span>
    </div>

    {{ $this->table }}

</x-filament-panels::page>
