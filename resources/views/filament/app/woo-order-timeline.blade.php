<x-filament-widgets::widget>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;padding:.5rem .9rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:.6rem;font-size:.8rem;color:#374151;">
        <span style="font-weight:700;">⏱ Timpi medii {{ $year }} (de la primirea comenzii):</span>

        <span>📦 → 🚚 Ridicat curier: <b style="color:#1d4ed8;">{{ $toAwb }}</b> <span style="color:#9ca3af;">({{ $nAwb }})</span></span>
        <span style="color:#d1d5db;">›</span>
        <span>→ ✅ Finalizare: <b style="color:#b45309;">{{ $toDone }}</b> <span style="color:#9ca3af;">({{ $nDone }})</span></span>
        <span style="color:#d1d5db;">›</span>
        <span>→ 🏁 Livrat la client: <b style="color:#15803d;">{{ $toDelivered }}</b> <span style="color:#9ca3af;">({{ $nDelivered }})</span></span>
        <span style="color:#9ca3af;font-size:.72rem;">· median (Sameday real + flotă proprie 8h)</span>
    </div>
</x-filament-widgets::widget>
