<x-filament-panels::page>
    <div class="flex flex-col gap-4">

        {{-- Selector template --}}
        <div class="flex flex-wrap gap-2 items-center">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Template:</span>
            @foreach($this->getTemplates() as $tpl)
                <button
                    wire:click="switchTemplate({{ $tpl->id }})"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium transition
                        {{ $templateId === $tpl->id
                            ? 'bg-primary-600 text-white'
                            : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700' }}"
                >
                    {{ $tpl->name }}
                    <span class="ml-1 opacity-60 text-xs">({{ $tpl->layout }})</span>
                </button>
            @endforeach

            <a href="{{ \App\Filament\App\Resources\GraphicTemplateResource::getUrl('create') }}"
               class="px-3 py-1.5 rounded-lg text-sm font-medium bg-success-100 text-success-700 hover:bg-success-200 transition">
                + Nou
            </a>
        </div>

        {{-- Split view: Formular stânga | Preview dreapta --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- ── Coloana FORMULAR ──────────────────────────────────────────── --}}
            <div class="flex flex-col gap-4">

                {{-- Informații generale --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Informații</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nume template</label>
                            <input type="text" wire:model="name"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Layout</label>
                            <select wire:model="templateLayout"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                                <option value="product">Product (imagine produs dreapta)</option>
                                <option value="brand">Brand (logo brand mare dreapta)</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Culori --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Culori</h3>
                    <div class="flex items-center gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Culoare principală</label>
                            <div class="flex items-center gap-2">
                                <input type="color" wire:model="primary_color"
                                    class="h-10 w-16 rounded cursor-pointer border border-gray-300">
                                <input type="text" wire:model="primary_color"
                                    class="w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm font-mono">
                            </div>
                        </div>
                        <div class="flex-1">
                            <p class="text-xs text-gray-400 mt-5">Afectează bara de jos, bara verticală accent, butonul CTA și bara accentului din text.</p>
                        </div>
                    </div>
                </div>

                {{-- Bara de jos --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Bara de jos</h3>
                    <div class="flex flex-col gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Text principal</label>
                            <input type="text" wire:model="bottom_text"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Text secundar (adresă / contact)</label>
                            <input type="text" wire:model="bottom_subtext"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        </div>
                        <div class="flex gap-6 pt-1">
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                                <input type="checkbox" wire:model="show_truck" class="rounded">
                                Iconița camion
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                                <input type="checkbox" wire:model="show_rainbow_bar" class="rounded">
                                Bara curcubeu
                            </label>
                        </div>
                    </div>
                </div>

                {{-- CTA --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Buton CTA</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Text buton</label>
                        <input type="text" wire:model="cta_text"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                    </div>
                </div>

                {{-- Proporții fonturi --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Proporții & Fonturi</h3>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Logo scale</label>
                            <input type="range" wire:model="logo_scale" min="0.15" max="0.60" step="0.01"
                                class="w-full accent-primary-600">
                            <span class="text-xs text-gray-400">{{ number_format($logo_scale * 100, 0) }}%</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Font titlu</label>
                            <input type="range" wire:model="title_size_pct" min="0.04" max="0.12" step="0.001"
                                class="w-full accent-primary-600">
                            <span class="text-xs text-gray-400">{{ number_format($title_size_pct * 1080, 0) }}px</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Font subtitlu</label>
                            <input type="range" wire:model="subtitle_size_pct" min="0.015" max="0.06" step="0.001"
                                class="w-full accent-primary-600">
                            <span class="text-xs text-gray-400">{{ number_format($subtitle_size_pct * 1080, 0) }}px</span>
                        </div>
                    </div>
                </div>

                {{-- Preview text demo --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Text demo pentru preview</h3>
                    <div class="flex flex-col gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titlu</label>
                            <input type="text" wire:model="previewTitle"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subtitlu</label>
                            <input type="text" wire:model="previewSub"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Label (eyebrow)</label>
                            <input type="text" wire:model="previewLabel"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        </div>
                    </div>
                </div>

                {{-- Butoane acțiuni --}}
                <div class="flex gap-3">
                    <button wire:click="save"
                        class="flex-1 px-4 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-medium hover:bg-gray-200 transition">
                        <span wire:loading.remove wire:target="save">Salvează</span>
                        <span wire:loading wire:target="save">Salvează...</span>
                    </button>
                    <button wire:click="generatePreview"
                        class="flex-1 px-4 py-2.5 rounded-lg bg-primary-600 text-white text-sm font-semibold hover:bg-primary-700 transition">
                        <span wire:loading.remove wire:target="generatePreview">Generează Preview</span>
                        <span wire:loading wire:target="generatePreview">Se renderează...</span>
                    </button>
                </div>
            </div>

            {{-- ── Coloana PREVIEW ───────────────────────────────────────────── --}}
            <div class="flex flex-col gap-3 lg:sticky lg:top-4 lg:self-start">
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Preview</h3>
                        @if($previewUrl)
                            <a href="{{ $previewUrl }}" target="_blank"
                               class="text-xs text-primary-600 hover:underline">
                                Deschide complet →
                            </a>
                        @endif
                    </div>

                    @if($previewUrl)
                        <img src="{{ $previewUrl }}"
                             alt="Template preview"
                             class="w-full rounded-lg shadow-md"
                             wire:loading.class="opacity-40"
                             wire:target="generatePreview">
                    @else
                        <div class="aspect-square rounded-lg bg-gray-100 dark:bg-gray-800 flex flex-col items-center justify-center gap-3 text-gray-400">
                            <x-filament::icon icon="heroicon-o-photo" class="w-16 h-16 opacity-30" />
                            <p class="text-sm">Apasă "Generează Preview"</p>
                        </div>
                    @endif

                    <div wire:loading wire:target="generatePreview"
                         class="mt-2 text-xs text-center text-gray-400 animate-pulse">
                        Se renderează imaginea...
                    </div>
                </div>

                @if($previewUrl)
                    <p class="text-xs text-gray-400 text-center">
                        1080×1080px • JPEG • Template: <strong>{{ $templateLayout }}</strong>
                    </p>
                @endif
            </div>

        </div>
    </div>
</x-filament-panels::page>
