<x-filament-panels::page>

    @php $stats = $this->getStats(); @endphp

    {{-- Stat cards --}}
    <div class="erp-stat-grid--5" style="margin-bottom: 1.5rem;">

        <div class="erp-stat">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-archive-box" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Total importate</p>
                <p class="erp-stat-value">{{ number_format($stats['total'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat erp-stat--info">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-photo" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Cu poză</p>
                <p class="erp-stat-value">{{ number_format($stats['withImage'], 0, '.', '') }}</p>
                @if($stats['total'] > 0)
                    <p class="erp-stat-sub">{{ round($stats['withImage'] / $stats['total'] * 100) }}%</p>
                @endif
            </div>
        </div>

        <div class="erp-stat erp-stat--info">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-document-text" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Cu descriere</p>
                <p class="erp-stat-value">{{ number_format($stats['withDesc'], 0, '.', '') }}</p>
                @if($stats['total'] > 0)
                    <p class="erp-stat-sub">{{ round($stats['withDesc'] / $stats['total'] * 100) }}%</p>
                @endif
            </div>
        </div>

        <div class="erp-stat erp-stat--info">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-tag" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Cu categorie</p>
                <p class="erp-stat-value">{{ number_format($stats['withCat'], 0, '.', '') }}</p>
                @if($stats['total'] > 0)
                    <p class="erp-stat-sub">{{ round($stats['withCat'] / $stats['total'] * 100) }}%</p>
                @endif
            </div>
        </div>

        <div class="erp-stat erp-stat--success">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-check-badge" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Gata de publicat</p>
                <p class="erp-stat-value">{{ number_format($stats['readyToPub'], 0, '.', '') }}</p>
                @if($stats['total'] > 0)
                    <p class="erp-stat-sub">{{ round($stats['readyToPub'] / $stats['total'] * 100) }}%</p>
                @endif
            </div>
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
