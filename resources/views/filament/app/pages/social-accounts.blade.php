<x-filament-panels::page>

    @php $accounts = $this->getAccounts(); @endphp

    @if($accounts->isEmpty())
        <div class="text-center py-12 text-gray-400">
            <x-filament::icon icon="heroicon-o-share" class="w-12 h-12 mx-auto mb-3 opacity-40" />
            <p class="text-lg font-medium">Niciun cont conectat</p>
            <p class="text-sm mt-1">Adaugă un cont Facebook din butonul de sus.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($accounts as $account)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow p-5">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold text-sm">
                                FB
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $account->name }}</p>
                                <p class="text-xs text-gray-500">Page ID: {{ $account->account_id }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap">
                            {{-- Status token --}}
                            @if($account->isTokenExpired())
                                <span class="text-xs px-2 py-1 rounded-full bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 font-medium">
                                    ⚠️ Token expirat
                                </span>
                            @elseif($account->isTokenExpiringSoon())
                                <span class="text-xs px-2 py-1 rounded-full bg-orange-100 text-orange-700 font-medium">
                                    ⚠️ Expiră curând
                                </span>
                            @else
                                <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 font-medium">
                                    ✓ Token activ
                                </span>
                            @endif

                            {{-- Activ/inactiv --}}
                            <span class="text-xs px-2 py-1 rounded-full {{ $account->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $account->is_active ? 'Activ' : 'Inactiv' }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm text-gray-600 dark:text-gray-400">
                        <div>
                            <p class="text-xs text-gray-400">Postări fetchate</p>
                            <p class="font-semibold text-gray-800 dark:text-gray-200">{{ $account->fetchedPosts()->count() }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Stil analizat</p>
                            <p class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ $account->style_analyzed_at ? $account->style_analyzed_at->format('d.m.Y') : '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Token expiră</p>
                            <p class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ $account->token_expires_at ? $account->token_expires_at->format('d.m.Y') : 'Nedefinit' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Profil stil activ</p>
                            <p class="font-semibold text-gray-800 dark:text-gray-200">
                                {{ $account->activeStyleProfile() ? 'Da (' . $account->activeStyleProfile()->posts_analyzed . ' postări)' : 'Nu' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2 flex-wrap">
                        <button
                            wire:click="fetchPosts({{ $account->id }})"
                            class="px-3 py-1.5 rounded-lg text-sm bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300 font-medium transition"
                        >
                            📥 Fetch postări istorice
                        </button>
                        <button
                            wire:click="analyzeStyle({{ $account->id }})"
                            class="px-3 py-1.5 rounded-lg text-sm bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-300 font-medium transition"
                        >
                            🧠 Analizează stil
                        </button>
                        <button
                            wire:click="toggleActive({{ $account->id }})"
                            class="px-3 py-1.5 rounded-lg text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 font-medium transition"
                        >
                            {{ $account->is_active ? 'Dezactivează' : 'Activează' }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Referințe vizuale --}}
    @php $references = $this->getStyleReferences(); @endphp
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow p-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Referințe vizuale stil</h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    Selectează imagini și generează automat structuri de template pentru editorul grafic.
                </p>
            </div>
            <span class="text-xs text-gray-400">{{ count($references) }} imagine(i)</span>
        </div>

        @if(empty($references))
            <div class="text-center py-8 text-gray-400 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-lg">
                <x-filament::icon icon="heroicon-o-photo" class="w-10 h-10 mx-auto mb-2 opacity-30" />
                <p class="text-sm">Nicio referință încărcată</p>
                <p class="text-xs mt-1">Folosește butonul "Încarcă referințe vizuale" din sus.</p>
            </div>
        @else
            {{-- Grid imagini cu selecție --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                @foreach($references as $ref)
                    @php
                        $isSelected = in_array($ref['path'], $this->selectedReferences, true);
                    @endphp
                    <div
                        wire:click="toggleReferenceSelection('{{ addslashes($ref['path']) }}')"
                        class="relative rounded-lg overflow-hidden border-2 cursor-pointer transition-all
                            {{ $isSelected
                                ? 'border-violet-500 ring-2 ring-violet-400 ring-offset-1'
                                : 'border-gray-200 dark:border-gray-700 hover:border-violet-300' }}"
                    >
                        {{-- Checkbox indicator --}}
                        <div class="absolute top-2 left-2 z-10">
                            <div class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold transition-all
                                {{ $isSelected
                                    ? 'bg-violet-500 text-white'
                                    : 'bg-white/80 border border-gray-300 text-transparent' }}">
                                ✓
                            </div>
                        </div>

                        <img
                            src="{{ $ref['url'] }}"
                            alt="{{ $ref['name'] }}"
                            class="w-full h-28 object-cover {{ $isSelected ? 'opacity-90' : '' }}"
                        />
                        <div class="p-1.5 bg-gray-50 dark:bg-gray-700 flex items-center justify-between gap-1">
                            <span class="text-xs text-gray-400 truncate">{{ $ref['size'] }}</span>
                            <button
                                wire:click.stop="deleteStyleReference('{{ addslashes($ref['path']) }}')"
                                class="shrink-0 px-2 py-0.5 rounded text-xs bg-red-100 text-red-700 hover:bg-red-200 transition font-medium"
                            >
                                Șterge
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Bara de acțiuni pentru generare template-uri --}}
            <div class="mt-4 flex items-center justify-between gap-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    @if(count($this->selectedReferences) > 0)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300 text-sm font-medium">
                            <span class="w-4 h-4 rounded-full bg-violet-500 text-white text-xs flex items-center justify-center font-bold">
                                {{ count($this->selectedReferences) }}
                            </span>
                            {{ count($this->selectedReferences) === 1 ? 'imagine selectată' : 'imagini selectate' }}
                        </span>
                    @else
                        <span class="text-sm text-gray-400 italic">
                            Click pe imagini pentru a le selecta ca referință
                        </span>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    @if(count($this->selectedReferences) > 0)
                        <button
                            wire:click="$set('selectedReferences', [])"
                            class="px-3 py-1.5 rounded-lg text-sm bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 transition font-medium"
                        >
                            Deselectează tot
                        </button>
                    @endif

                    @if(count($this->selectedReferences) > 0)
                        <button
                            wire:click="generateTemplates"
                            wire:loading.attr="disabled"
                            wire:target="generateTemplates"
                            style="background:#7c3aed;color:#fff;padding:6px 16px;border-radius:8px;font-size:14px;font-weight:600;border:none;cursor:pointer;transition:background .15s"
                            onmouseover="this.style.background='#6d28d9'"
                            onmouseout="this.style.background='#7c3aed'"
                        >
                            <span wire:loading.remove wire:target="generateTemplates">🪄 Generează template-uri</span>
                            <span wire:loading wire:target="generateTemplates">⏳ Se analizează...</span>
                        </button>
                    @else
                        <button
                            disabled
                            style="background:#f3f4f6;color:#9ca3af;padding:6px 16px;border-radius:8px;font-size:14px;font-weight:600;border:none;cursor:not-allowed"
                        >
                            🪄 Generează template-uri
                        </button>
                    @endif
                </div>
            </div>

            {{-- Notă despre ce face butonul --}}
            @if(count($this->selectedReferences) > 0)
                <div class="mt-3 p-3 rounded-lg bg-violet-50 dark:bg-violet-900/20 border border-violet-100 dark:border-violet-800">
                    <p class="text-xs text-violet-700 dark:text-violet-300">
                        <strong>Claude AI</strong> va analiza {{ count($this->selectedReferences) }} imagine(i),
                        va detecta structuri de layout recurente și va genera șabloane grafice refolosibile
                        în editorul de template-uri. Durată estimată: 15–30 secunde.
                    </p>
                </div>
            @endif
        @endif
    </div>

    {{-- Setări API --}}
    <div class="mt-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow p-5">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-3">Setări API</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <p class="text-xs text-gray-400 mb-1">Gemini API Key</p>
                <p class="font-mono text-xs text-gray-600 dark:text-gray-400">
                    {{ \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_GEMINI_API_KEY) ? '✓ Setat' : '✗ Lipsă' }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-400 mb-1">Meta App ID</p>
                <p class="font-mono text-xs text-gray-600 dark:text-gray-400">
                    {{ \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_META_APP_ID) ?: '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-400 mb-1">Meta App Secret</p>
                <p class="font-mono text-xs text-gray-600 dark:text-gray-400">
                    {{ \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_META_APP_SECRET) ? '✓ Setat' : '—' }}
                </p>
            </div>
        </div>
    </div>

</x-filament-panels::page>
