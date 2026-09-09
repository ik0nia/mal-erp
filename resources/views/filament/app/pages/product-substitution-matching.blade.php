<x-filament-panels::page>

    @php $stats = $this->getStats(); @endphp

    {{-- Stat cards --}}
    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.75rem; margin-bottom: 1.5rem;">

        <div class="erp-stat">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-cube" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Produse totale</p>
                <p class="erp-stat-value">{{ number_format($stats['totalSource'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-inbox" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Neprocesate</p>
                <p class="erp-stat-value">{{ number_format($stats['unprocessed'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat erp-stat--warning">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-clock" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">În așteptare</p>
                <p class="erp-stat-value">{{ number_format($stats['pending'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat erp-stat--success">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-check-circle" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Aprobate</p>
                <p class="erp-stat-value">{{ number_format($stats['approved'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat erp-stat--danger">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-x-circle" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Respinse</p>
                <p class="erp-stat-value">{{ number_format($stats['rejected'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-question-mark-circle" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Fără match</p>
                <p class="erp-stat-value">{{ number_format($stats['noMatch'], 0, '.', '') }}</p>
            </div>
        </div>

        <div class="erp-stat erp-stat--info">
            <div class="erp-stat-icon"><x-filament::icon icon="heroicon-o-cpu-chip" /></div>
            <div class="erp-stat-body">
                <p class="erp-stat-label">Procesate total</p>
                <p class="erp-stat-value">{{ number_format($stats['total'], 0, '.', '') }}</p>
            </div>
        </div>

    </div>

    @if($stats['total'] === 0)
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #1d4ed8;">
        <strong>Nicio propunere generată încă.</strong>
        Apasă <strong>Pornește agenți AI</strong> pentru a începe analiza.
        Agenții vor căuta automat echivalente Toya pentru cele {{ number_format($stats['totalSource'], 0, '.', '') }} produse existente.
    </div>
    @endif

    {{ $this->table }}

</x-filament-panels::page>
