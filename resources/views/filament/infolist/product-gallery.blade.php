@php
    /** @var \App\Models\WooProduct $record */
    $images = $record->images()->get();
    $canManage = auth()->user()?->isAdmin()
        || in_array(auth()->user()?->role, [
            \App\Models\User::ROLE_MANAGER,
            \App\Models\User::ROLE_DIRECTOR_ACHIZITII ?? 'director_achizitii',
        ], true);
    $sourceLabel = fn(string $s) => match($s) {
        'toya'        => 'Toya',
        'woocommerce' => 'Woo',
        default       => 'Manual',
    };
    $sourceColor = fn(string $s) => match($s) {
        'toya'        => '#2563eb',
        'woocommerce' => '#7F54B3',
        default       => '#6b7280',
    };
@endphp

<div style="padding: 0.5rem 0;">
    @if($images->isEmpty())
        <div style="text-align:center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
            Nicio imagine adăugată.
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem;">
            @foreach($images as $image)
                <div style="position: relative; border-radius: 10px; overflow: hidden; border: 2px solid {{ $image->is_primary ? '#2563eb' : '#e5e7eb' }}; background: #f9fafb;">
                    {{-- Imagine --}}
                    <div style="aspect-ratio: 1; overflow: hidden; background: #f3f4f6;">
                        <img
                            src="{{ $image->url }}"
                            alt=""
                            loading="lazy"
                            style="width: 100%; height: 100%; object-fit: cover;"
                            onerror="this.src='https://placehold.co/140x140?text=Eroare'"
                        >
                    </div>

                    {{-- Badge Primary --}}
                    @if($image->is_primary)
                        <div style="position: absolute; top: 5px; left: 5px; background: #2563eb; color: white; border-radius: 6px; padding: 2px 7px; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.03em;">
                            ✓ Principală
                        </div>
                    @endif

                    {{-- Badge Sursă --}}
                    <div style="position: absolute; top: 5px; right: 5px; background: {{ $sourceColor($image->source) }}; color: white; border-radius: 6px; padding: 2px 6px; font-size: 0.6rem; font-weight: 600;">
                        {{ $sourceLabel($image->source) }}
                    </div>

                    {{-- Butoane acțiuni --}}
                    @if($canManage)
                        <div style="display: flex; gap: 4px; padding: 6px; background: rgba(0,0,0,0.03);">
                            @if(! $image->is_primary)
                                <button
                                    type="button"
                                    title="Setează ca principală"
                                    x-on:click="$wire.mountAction('gallery_set_primary', { image_id: {{ $image->id }} })"
                                    style="flex: 1; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 6px; padding: 4px; font-size: 0.7rem; cursor: pointer; font-weight: 600;"
                                >⭐ Primary</button>
                            @else
                                <div style="flex: 1; text-align: center; color: #9ca3af; font-size: 0.7rem; padding: 4px;">Principală</div>
                            @endif
                            <button
                                type="button"
                                title="Șterge imaginea"
                                x-on:click="$wire.mountAction('gallery_delete_image', { image_id: {{ $image->id }} })"
                                style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; padding: 4px 7px; font-size: 0.75rem; cursor: pointer;"
                            >✕</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Butoane globale --}}
    @if($canManage)
        <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap;">
            <button
                type="button"
                x-on:click="$wire.mountAction('gallery_add_url')"
                style="background: #f9fafb; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 14px; font-size: 0.8rem; cursor: pointer; font-weight: 500;"
            >+ Adaugă URL imagine</button>

            @if($record->source === \App\Models\WooProduct::SOURCE_TOYA_API)
                <button
                    type="button"
                    x-on:click="$wire.mountAction('gallery_import_toya')"
                    style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 8px; padding: 6px 14px; font-size: 0.8rem; cursor: pointer; font-weight: 500;"
                >↓ Import poze Toya ({{ (function() use ($record) {
                    $raw = $record->getRawOriginal('data') ?? '{}';
                    $d = json_decode($raw, true);
                    if (is_string($d)) { $d = json_decode($d, true) ?? []; }
                    return count($d['images_additional'] ?? []);
                })() }})</button>
            @endif
        </div>
    @endif
</div>
