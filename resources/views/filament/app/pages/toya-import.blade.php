<x-filament-panels::page>

    @php $stats = $this->getStats(); @endphp

    {{-- Stat cards --}}
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 1rem; text-align: center;">
            <div style="font-size: 1.875rem; font-weight: 700; color: #111827;">{{ number_format($stats['total'], 0, '.', '') }}</div>
            <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem;">Total importate</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 1rem; text-align: center;">
            <div style="font-size: 1.875rem; font-weight: 700; color: #2563eb;">{{ number_format($stats['withImage'], 0, '.', '') }}</div>
            <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem;">Cu poză</div>
            @if($stats['total'] > 0)
                <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.125rem;">{{ round($stats['withImage'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 1rem; text-align: center;">
            <div style="font-size: 1.875rem; font-weight: 700; color: #9333ea;">{{ number_format($stats['withDesc'], 0, '.', '') }}</div>
            <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem;">Cu descriere</div>
            @if($stats['total'] > 0)
                <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.125rem;">{{ round($stats['withDesc'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 1rem; text-align: center;">
            <div style="font-size: 1.875rem; font-weight: 700; color: #f97316;">{{ number_format($stats['withCat'], 0, '.', '') }}</div>
            <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem;">Cu categorie</div>
            @if($stats['total'] > 0)
                <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.125rem;">{{ round($stats['withCat'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 1rem; text-align: center;">
            <div style="font-size: 1.875rem; font-weight: 700; color: #16a34a;">{{ number_format($stats['readyToPub'], 0, '.', '') }}</div>
            <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem;">Gata de publicat</div>
            @if($stats['total'] > 0)
                <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.125rem;">{{ round($stats['readyToPub'] / $stats['total'] * 100) }}%</div>
            @endif
        </div>

    </div>

    {{-- Comandă de import --}}
    @if($stats['total'] === 0)
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #1d4ed8;">
        <strong>Niciun produs importat încă.</strong>
        Rulează comanda artisan pentru a importa produsele Toya:<br>
        <code style="font-family: monospace; background: #dbeafe; padding: 0.125rem 0.5rem; border-radius: 0.25rem; margin-top: 0.25rem; display: inline-block;">
            php artisan toya:import-products
        </code>
    </div>
    @else
    <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #4b5563;">
        <strong>Actualizare produse noi:</strong>
        <code style="font-family: monospace; background: #f3f4f6; padding: 0.125rem 0.5rem; border-radius: 0.25rem; margin-left: 0.25rem;">
            php artisan toya:import-products
        </code>
        &nbsp;·&nbsp;
        <strong>Re-import complet:</strong>
        <code style="font-family: monospace; background: #f3f4f6; padding: 0.125rem 0.5rem; border-radius: 0.25rem; margin-left: 0.25rem;">
            php artisan toya:import-products --force
        </code>
    </div>
    @endif

    {{ $this->table }}

</x-filament-panels::page>
