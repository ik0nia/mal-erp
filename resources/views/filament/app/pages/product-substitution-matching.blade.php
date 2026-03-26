<x-filament-panels::page>

    @php $stats = $this->getStats(); @endphp

    {{-- Stat cards --}}
    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.75rem; margin-bottom: 1.5rem;">

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #111827;">{{ number_format($stats['totalSource'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Produse totale</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #9ca3af;">{{ number_format($stats['unprocessed'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Neprocesate</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #eab308;">{{ number_format($stats['pending'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">În așteptare</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #16a34a;">{{ number_format($stats['approved'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Aprobate</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;">{{ number_format($stats['rejected'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Respinse</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #9ca3af;">{{ number_format($stats['noMatch'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Fără match</div>
        </div>

        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; padding: 0.75rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #2563eb;">{{ number_format($stats['total'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Procesate total</div>
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
