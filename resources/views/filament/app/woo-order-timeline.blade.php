<x-filament-widgets::widget>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;padding:.5rem .9rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:.6rem;font-size:.8rem;color:#374151;">
        <span style="font-weight:700;">⏱ Timpi medii {{ $year }}:</span>

        <span>📦 → ✅ Comandă → Finalizare: <b style="color:#b45309;">{{ $toDone }}</b> <span style="color:#9ca3af;">({{ $nDone }})</span></span>
        <span style="color:#d1d5db;">›</span>
        <span>🚚 Ridicare Sameday (finalizare → ridicat): <b style="color:#7c3aed;">{{ $samedayPickup }}</b> <span style="color:#9ca3af;">({{ $nPickup }})</span></span>
        <span style="color:#d1d5db;">›</span>
        <span>📬 Livrare Sameday (finalizare → livrat): <b style="color:#1d4ed8;">{{ $samedayDeliv }}</b> <span style="color:#9ca3af;">({{ $nSameday }})</span></span>
        <span style="color:#d1d5db;">›</span>
        <span>🏁 Livrare reală (comandă → livrat la client): <b style="color:#15803d;">{{ $realDeliv }}</b> <span style="color:#9ca3af;">({{ $nReal }})</span></span>

        <span style="color:#9ca3af;font-size:.72rem;">· median · livrare reală = Sameday real + flotă proprie 8h</span>
    </div>
</x-filament-widgets::widget>
