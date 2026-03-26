<x-filament-panels::page>
    @php $stats = $this->getStats(); @endphp

    {{-- Stats cards --}}
    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.75rem; margin-bottom: 1.5rem;">
        <div style="background: #fff; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #111827;">{{ number_format($stats['total'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Total propuneri</div>
        </div>
        <div style="background: #fffbeb; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #d97706;">{{ number_format($stats['pending'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">In asteptare</div>
        </div>
        <div style="background: #f0fdf4; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #16a34a;">{{ number_format($stats['approved'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Aprobate</div>
        </div>
        <div style="background: #fef2f2; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #dc2626;">{{ number_format($stats['rejected'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Respinse</div>
        </div>
        <div style="background: #f9fafb; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #6b7280;">{{ number_format($stats['no_match'], 0, '.', '') }}</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Fara potrivire</div>
        </div>
        <div style="background: #eff6ff; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1rem; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #2563eb;">
                {{ number_format($stats['products_with_cat'], 0, '.', '') }}
                <span style="font-size: 0.875rem; font-weight: 400; color: #6b7280;">/ {{ number_format($stats['products_total'], 0, '.', '') }}</span>
            </div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">Produse cu categorie</div>
        </div>
    </div>

    @if($stats['total'] === 0)
        <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.75rem; padding: 1.5rem; text-align: center; margin-bottom: 1.5rem;">
            <x-filament::icon icon="heroicon-o-sparkles" style="width: 2.5rem; height: 2.5rem; color: #60a5fa; margin: 0 auto 0.75rem;" />
            <p style="color: #1d4ed8; font-weight: 500;">Nicio propunere inca.</p>
            <p style="color: #2563eb; font-size: 0.875rem; margin-top: 0.25rem;">
                Apasa <strong>„Porneste 15 agenti AI"</strong> din dreapta sus pentru a genera propunerile de categorii.
            </p>
        </div>
    @elseif($stats['pending'] === 0 && $stats['approved'] > 0)
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.75rem; padding: 1rem; text-align: center; margin-bottom: 1.5rem;">
            <p style="color: #15803d; font-size: 0.875rem;">
                ✓ Toate propunerile au fost procesate. Apasa <strong>„Aplica aprobate"</strong> pentru a actualiza produsele.
            </p>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
