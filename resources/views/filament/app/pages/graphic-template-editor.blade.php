<x-filament-panels::page>
    <div style="display:flex; flex-direction:column; gap:1rem;">

        {{-- Selector template --}}
        <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;">
            <span style="font-size:0.875rem; font-weight:500; color:#6b7280;">Template:</span>
            @foreach($this->getTemplates() as $tpl)
                <button
                    wire:click="switchTemplate({{ $tpl->id }})"
                    style="padding:0.375rem 0.75rem; border-radius:0.5rem; font-size:0.875rem; font-weight:500; border:none; cursor:pointer; transition:background 0.15s;
                        {{ $templateId === $tpl->id
                            ? 'background:#8B1A1A; color:#fff;'
                            : 'background:#f3f4f6; color:#374151;' }}"
                >
                    {{ $tpl->name }}
                    <span style="margin-left:0.25rem; opacity:0.6; font-size:0.75rem;">({{ $tpl->layout }})</span>
                </button>
            @endforeach

            <a href="{{ \App\Filament\App\Resources\GraphicTemplateResource::getUrl('create') }}"
               style="padding:0.375rem 0.75rem; border-radius:0.5rem; font-size:0.875rem; font-weight:500; background:#dcfce7; color:#15803d; text-decoration:none; transition:background 0.15s;">
                + Nou
            </a>
        </div>

        {{-- Split view: Formular stanga | Preview dreapta --}}
        <div style="display:grid; grid-template-columns:1fr; gap:1.5rem;"
             class="lg-grid-2-cols">

            {{-- ── Coloana FORMULAR ──────────────────────────────────────────── --}}
            <div style="display:flex; flex-direction:column; gap:1rem;">

                {{-- Informatii generale --}}
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1.25rem;">
                    <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 1rem;">Informatii</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                        <div style="grid-column:span 2;">
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Nume template</label>
                            <input type="text" wire:model="name"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Layout</label>
                            <select wire:model="templateLayout"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                <option value="product">Product (imagine produs dreapta)</option>
                                <option value="brand">Brand (logo brand mare dreapta)</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Culori --}}
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1.25rem;">
                    <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 1rem;">Culori</h3>
                    <div style="display:flex; align-items:center; gap:1rem;">
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Culoare principala</label>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <input type="color" wire:model="primary_color"
                                    style="height:2.5rem; width:4rem; border-radius:0.25rem; cursor:pointer; border:1px solid #d1d5db;">
                                <input type="text" wire:model="primary_color"
                                    style="width:7rem; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; font-family:monospace; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            </div>
                        </div>
                        <div style="flex:1;">
                            <p style="font-size:0.75rem; color:#9ca3af; margin-top:1.25rem;">Afecteaza bara de jos, bara verticala accent, butonul CTA si bara accentului din text.</p>
                        </div>
                    </div>
                </div>

                {{-- Bara de jos --}}
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1.25rem;">
                    <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 1rem;">Bara de jos</h3>
                    <div style="display:flex; flex-direction:column; gap:0.75rem;">
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Text principal</label>
                            <input type="text" wire:model="bottom_text"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Text secundar (adresa / contact)</label>
                            <input type="text" wire:model="bottom_subtext"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        </div>
                        <div style="display:flex; gap:1.5rem; padding-top:0.25rem;">
                            <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.875rem; color:#374151; cursor:pointer;">
                                <input type="checkbox" wire:model="show_truck" style="border-radius:0.25rem;">
                                Iconita camion
                            </label>
                            <label style="display:flex; align-items:center; gap:0.5rem; font-size:0.875rem; color:#374151; cursor:pointer;">
                                <input type="checkbox" wire:model="show_rainbow_bar" style="border-radius:0.25rem;">
                                Bara curcubeu
                            </label>
                        </div>
                    </div>
                </div>

                {{-- CTA --}}
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1.25rem;">
                    <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 1rem;">Buton CTA</h3>
                    <div>
                        <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Text buton</label>
                        <input type="text" wire:model="cta_text"
                            style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    </div>
                </div>

                {{-- Proportii fonturi --}}
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1.25rem;">
                    <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 1rem;">Proportii & Fonturi</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem;">
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Logo scale</label>
                            <input type="range" wire:model="logo_scale" min="0.15" max="0.60" step="0.01"
                                style="width:100%; accent-color:#8B1A1A;">
                            <span style="font-size:0.75rem; color:#9ca3af;">{{ number_format($logo_scale * 100, 0, '.', '') }}%</span>
                        </div>
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Font titlu</label>
                            <input type="range" wire:model="title_size_pct" min="0.04" max="0.12" step="0.001"
                                style="width:100%; accent-color:#8B1A1A;">
                            <span style="font-size:0.75rem; color:#9ca3af;">{{ number_format($title_size_pct * 1080, 0, '.', '') }}px</span>
                        </div>
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Font subtitlu</label>
                            <input type="range" wire:model="subtitle_size_pct" min="0.015" max="0.06" step="0.001"
                                style="width:100%; accent-color:#8B1A1A;">
                            <span style="font-size:0.75rem; color:#9ca3af;">{{ number_format($subtitle_size_pct * 1080, 0, '.', '') }}px</span>
                        </div>
                    </div>
                </div>

                {{-- Preview text demo --}}
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1.25rem;">
                    <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 1rem;">Text demo pentru preview</h3>
                    <div style="display:flex; flex-direction:column; gap:0.75rem;">
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Titlu</label>
                            <input type="text" wire:model="previewTitle"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Subtitlu</label>
                            <input type="text" wire:model="previewSub"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.875rem; font-weight:500; color:#374151; margin-bottom:0.25rem;">Label (eyebrow)</label>
                            <input type="text" wire:model="previewLabel"
                                style="width:100%; border-radius:0.5rem; border:1px solid #d1d5db; padding:0.5rem 0.75rem; font-size:0.875rem; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        </div>
                    </div>
                </div>

                {{-- Butoane actiuni --}}
                <div style="display:flex; gap:0.75rem;">
                    <button wire:click="save"
                        style="flex:1; padding:0.625rem 1rem; border-radius:0.5rem; background:#f3f4f6; color:#374151; font-size:0.875rem; font-weight:500; border:none; cursor:pointer; transition:background 0.15s;">
                        <span wire:loading.remove wire:target="save">Salveaza</span>
                        <span wire:loading wire:target="save">Salveaza...</span>
                    </button>
                    <button wire:click="generatePreview"
                        style="flex:1; padding:0.625rem 1rem; border-radius:0.5rem; background:#8B1A1A; color:#fff; font-size:0.875rem; font-weight:600; border:none; cursor:pointer; transition:background 0.15s;">
                        <span wire:loading.remove wire:target="generatePreview">Genereaza Preview</span>
                        <span wire:loading wire:target="generatePreview">Se randeaza...</span>
                    </button>
                </div>
            </div>

            {{-- ── Coloana PREVIEW ───────────────────────────────────────────── --}}
            <div style="display:flex; flex-direction:column; gap:0.75rem; position:sticky; top:1rem; align-self:start;">
                <div style="background:#fff; border-radius:0.75rem; border:1px solid #e5e7eb; padding:1rem;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem;">
                        <h3 style="font-size:0.875rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">Preview</h3>
                        @if($previewUrl)
                            <a href="{{ $previewUrl }}" target="_blank"
                               style="font-size:0.75rem; color:#8B1A1A; text-decoration:none;">
                                Deschide complet →
                            </a>
                        @endif
                    </div>

                    @if($previewUrl)
                        <img src="{{ $previewUrl }}"
                             alt="Template preview"
                             style="width:100%; border-radius:0.5rem; box-shadow:0 4px 6px rgba(0,0,0,0.1);"
                             wire:loading.class="opacity-40"
                             wire:target="generatePreview">
                    @else
                        <div style="aspect-ratio:1/1; border-radius:0.5rem; background:#f3f4f6; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.75rem; color:#9ca3af;">
                            <x-filament::icon icon="heroicon-o-photo" style="width:4rem; height:4rem; opacity:0.3;" />
                            <p style="font-size:0.875rem; margin:0;">Apasa "Genereaza Preview"</p>
                        </div>
                    @endif

                    <div wire:loading wire:target="generatePreview"
                         style="margin-top:0.5rem; font-size:0.75rem; text-align:center; color:#9ca3af;">
                        Se randeaza imaginea...
                    </div>
                </div>

                @if($previewUrl)
                    <p style="font-size:0.75rem; color:#9ca3af; text-align:center; margin:0;">
                        1080x1080px • JPEG • Template: <strong>{{ $templateLayout }}</strong>
                    </p>
                @endif
            </div>

        </div>
    </div>

<style>
@media (min-width: 1024px) {
    .lg-grid-2-cols { grid-template-columns: 1fr 1fr !important; }
}
</style>
</x-filament-panels::page>
