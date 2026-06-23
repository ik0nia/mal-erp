<x-filament-panels::page>

    {{-- Căutare --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1.25rem; margin-bottom:1.25rem;">
        <p style="font-size:0.85rem; color:#374151; margin:0 0 0.6rem; font-weight:600;">Caută o persoană vizată după email sau telefon</p>
        <div style="display:flex; gap:0.6rem; align-items:center;">
            <input type="text" wire:model="identifier" wire:keydown.enter="search"
                placeholder="ex: ion.popescu@email.ro sau 07xxxxxxxx"
                style="flex:1; padding:0.5rem 0.75rem; border:1px solid #d1d5db; border-radius:0.5rem; font-size:0.9rem;">
            <button wire:click="search" wire:loading.attr="disabled"
                style="padding:0.5rem 1.25rem; background:#4f46e5; color:#fff; border:none; border-radius:0.5rem; font-weight:600; cursor:pointer;">
                Caută
            </button>
        </div>
        <p style="font-size:0.72rem; color:#9ca3af; margin:0.5rem 0 0;">Căutarea este globală (ignoră scope-ul de locație) și read-only.</p>
    </div>

    @if($located !== null)
        @php $total = $this->totalFound(); @endphp

        @if($total === 0)
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1.25rem; color:#6b7280;">
                Nicio înregistrare găsită pentru <strong>{{ $located['identifier'] }}</strong>
                (căutat după {{ $located['searched_by'] }}).
            </div>
        @else
            {{-- Rezultate --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:0.75rem; padding:1.25rem; margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                    <p style="font-size:0.9rem; color:#111827; margin:0; font-weight:600;">
                        {{ $total }} înregistrări pentru „{{ $located['identifier'] }}"
                    </p>
                    <button wire:click="downloadExport"
                        style="padding:0.45rem 1rem; background:#16a34a; color:#fff; border:none; border-radius:0.5rem; font-weight:600; font-size:0.85rem; cursor:pointer;">
                        ⬇ Descarcă export (JSON)
                    </button>
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <tbody>
                    @foreach($located['records'] as $table => $rows)
                        @if(count($rows) > 0)
                            <tr style="border-top:1px solid #f3f4f6;">
                                <td style="padding:0.4rem 0.5rem; color:#374151;">{{ str_replace('_',' ',ucfirst($table)) }}</td>
                                <td style="padding:0.4rem 0.5rem; text-align:right; font-weight:600;">{{ count($rows) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Zonă periculoasă: ștergere --}}
            <div style="background:#fef6f5; border:1.5px solid #e0b4b0; border-left:5px solid #c0392b; border-radius:0.75rem; padding:1.25rem;">
                <p style="font-size:0.9rem; color:#7f1d1d; margin:0 0 0.4rem; font-weight:700;">Ștergere — „dreptul de a fi uitat"</p>
                <p style="font-size:0.8rem; color:#7f1d1d; margin:0 0 0.75rem;">
                    Anonimizează ireversibil datele personale de mai sus (numele/email/telefon/adresă), păstrând înregistrările pentru integritate contabilă.
                    Conturile de utilizator NU se anonimizează automat.
                </p>
                <div style="display:flex; gap:0.6rem; align-items:center;">
                    <input type="text" wire:model="confirmText"
                        placeholder="Tastează „{{ $located['identifier'] }}" pentru a confirma"
                        style="flex:1; padding:0.5rem 0.75rem; border:1px solid #e0b4b0; border-radius:0.5rem; font-size:0.85rem;">
                    <button wire:click="erase"
                        wire:confirm="Sigur anonimizezi ireversibil datele acestei persoane?"
                        style="padding:0.5rem 1.1rem; background:#c0392b; color:#fff; border:none; border-radius:0.5rem; font-weight:600; font-size:0.85rem; cursor:pointer;">
                        Anonimizează definitiv
                    </button>
                </div>
            </div>
        @endif
    @endif

</x-filament-panels::page>
