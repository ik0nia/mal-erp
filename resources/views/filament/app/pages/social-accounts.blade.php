<x-filament-panels::page>

    @php $accounts = $this->getAccounts(); @endphp

    @if($accounts->isEmpty())
        <div style="text-align: center; padding: 3rem 0; color: #9ca3af;">
            <x-filament::icon icon="heroicon-o-share" style="width: 3rem; height: 3rem; margin: 0 auto 0.75rem; opacity: 0.4;" />
            <p style="font-size: 1.125rem; font-weight: 500;">Niciun cont conectat</p>
            <p style="font-size: 0.875rem; margin-top: 0.25rem;">Adauga un cont Facebook din butonul de sus.</p>
        </div>
    @else
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            @foreach($accounts as $account)
                <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1.25rem;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 2.5rem; height: 2.5rem; border-radius: 9999px; background: #2563eb; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 0.875rem;">
                                FB
                            </div>
                            <div>
                                <p style="font-weight: 600; color: #111827;">{{ $account->name }}</p>
                                <p style="font-size: 0.75rem; color: #6b7280;">Page ID: {{ $account->account_id }}</p>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            {{-- Status token --}}
                            @if($account->isTokenExpired())
                                <span style="font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; background: #fee2e2; color: #b91c1c; font-weight: 500;">
                                    ⚠️ Token expirat
                                </span>
                            @elseif($account->isTokenExpiringSoon())
                                <span style="font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; background: #ffedd5; color: #c2410c; font-weight: 500;">
                                    ⚠️ Expira curand
                                </span>
                            @else
                                <span style="font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; background: #dcfce7; color: #15803d; font-weight: 500;">
                                    ✓ Token activ
                                </span>
                            @endif

                            {{-- Activ/inactiv --}}
                            <span style="font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; {{ $account->is_active ? 'background: #d1fae5; color: #047857;' : 'background: #f3f4f6; color: #6b7280;' }}">
                                {{ $account->is_active ? 'Activ' : 'Inactiv' }}
                            </span>
                        </div>
                    </div>

                    <div style="margin-top: 1rem; display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; font-size: 0.875rem; color: #6b7280;">
                        <div>
                            <p style="font-size: 0.75rem; color: #9ca3af;">Postari fetchate</p>
                            <p style="font-weight: 600; color: #1f2937;">{{ $account->fetchedPosts()->count() }}</p>
                        </div>
                        <div>
                            <p style="font-size: 0.75rem; color: #9ca3af;">Stil analizat</p>
                            <p style="font-weight: 600; color: #1f2937;">
                                {{ $account->style_analyzed_at ? $account->style_analyzed_at->format('d.m.Y') : '—' }}
                            </p>
                        </div>
                        <div>
                            <p style="font-size: 0.75rem; color: #9ca3af;">Token expira</p>
                            <p style="font-weight: 600; color: #1f2937;">
                                {{ $account->token_expires_at ? $account->token_expires_at->format('d.m.Y') : 'Nedefinit' }}
                            </p>
                        </div>
                        <div>
                            <p style="font-size: 0.75rem; color: #9ca3af;">Profil stil activ</p>
                            <p style="font-weight: 600; color: #1f2937;">
                                {{ $account->activeStyleProfile() ? 'Da (' . $account->activeStyleProfile()->posts_analyzed . ' postari)' : 'Nu' }}
                            </p>
                        </div>
                    </div>

                    <div style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button
                            wire:click="fetchPosts({{ $account->id }})"
                            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; background: #eff6ff; color: #1d4ed8; font-weight: 500; border: none; cursor: pointer; transition: background 0.15s;"
                            onmouseover="this.style.background='#dbeafe'"
                            onmouseout="this.style.background='#eff6ff'"
                        >
                            📥 Fetch postari istorice
                        </button>
                        <button
                            wire:click="analyzeStyle({{ $account->id }})"
                            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; background: #faf5ff; color: #7e22ce; font-weight: 500; border: none; cursor: pointer; transition: background 0.15s;"
                            onmouseover="this.style.background='#f3e8ff'"
                            onmouseout="this.style.background='#faf5ff'"
                        >
                            🧠 Analizeaza stil
                        </button>
                        <button
                            wire:click="toggleActive({{ $account->id }})"
                            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; background: #f3f4f6; color: #374151; font-weight: 500; border: none; cursor: pointer; transition: background 0.15s;"
                            onmouseover="this.style.background='#e5e7eb'"
                            onmouseout="this.style.background='#f3f4f6'"
                        >
                            {{ $account->is_active ? 'Dezactiveaza' : 'Activeaza' }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Referinte vizuale --}}
    @php $references = $this->getStyleReferences(); @endphp
    <div style="margin-top: 1.5rem; background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1.25rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <div>
                <h3 style="font-weight: 600; color: #1f2937;">Referinte vizuale stil</h3>
                <p style="font-size: 0.75rem; color: #6b7280; margin-top: 0.125rem;">
                    Selecteaza imagini si genereaza automat structuri de template pentru editorul grafic.
                </p>
            </div>
            <span style="font-size: 0.75rem; color: #9ca3af;">{{ count($references) }} imagine(i)</span>
        </div>

        @if(empty($references))
            <div style="text-align: center; padding: 2rem 0; color: #9ca3af; border: 2px dashed #e5e7eb; border-radius: 0.5rem;">
                <x-filament::icon icon="heroicon-o-photo" style="width: 2.5rem; height: 2.5rem; margin: 0 auto 0.5rem; opacity: 0.3;" />
                <p style="font-size: 0.875rem;">Nicio referinta incarcata</p>
                <p style="font-size: 0.75rem; margin-top: 0.25rem;">Foloseste butonul "Incarca referinte vizuale" din sus.</p>
            </div>
        @else
            {{-- Grid imagini cu selectie --}}
            <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 0.75rem;">
                @foreach($references as $ref)
                    @php
                        $isSelected = in_array($ref['path'], $this->selectedReferences, true);
                    @endphp
                    <div
                        wire:click="toggleReferenceSelection('{{ addslashes($ref['path']) }}')"
                        style="position: relative; border-radius: 0.5rem; overflow: hidden; cursor: pointer; transition: all 0.15s; border: 2px solid {{ $isSelected ? '#8b5cf6' : '#e5e7eb' }}; {{ $isSelected ? 'box-shadow: 0 0 0 2px #a78bfa, 0 0 0 3px #fff;' : '' }}"
                    >
                        {{-- Checkbox indicator --}}
                        <div style="position: absolute; top: 0.5rem; left: 0.5rem; z-index: 10;">
                            <div style="width: 1.25rem; height: 1.25rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; transition: all 0.15s; {{ $isSelected ? 'background: #8b5cf6; color: #fff;' : 'background: rgba(255,255,255,0.8); border: 1px solid #d1d5db; color: transparent;' }}">
                                ✓
                            </div>
                        </div>

                        <img
                            src="{{ $ref['url'] }}"
                            alt="{{ $ref['name'] }}"
                            style="width: 100%; height: 7rem; object-fit: cover; {{ $isSelected ? 'opacity: 0.9;' : '' }}"
                        />
                        <div style="padding: 0.375rem; background: #f9fafb; display: flex; align-items: center; justify-content: space-between; gap: 0.25rem;">
                            <span style="font-size: 0.75rem; color: #9ca3af; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $ref['size'] }}</span>
                            <button
                                wire:click.stop="deleteStyleReference('{{ addslashes($ref['path']) }}')"
                                style="flex-shrink: 0; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; background: #fee2e2; color: #b91c1c; font-weight: 500; border: none; cursor: pointer; transition: background 0.15s;"
                                onmouseover="this.style.background='#fecaca'"
                                onmouseout="this.style.background='#fee2e2'"
                            >
                                Sterge
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Bara de actiuni pentru generare template-uri --}}
            <div style="margin-top: 1rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    @if(count($this->selectedReferences) > 0)
                        <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0.75rem; border-radius: 9999px; background: #ede9fe; color: #6d28d9; font-size: 0.875rem; font-weight: 500;">
                            <span style="width: 1rem; height: 1rem; border-radius: 9999px; background: #8b5cf6; color: #fff; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                {{ count($this->selectedReferences) }}
                            </span>
                            {{ count($this->selectedReferences) === 1 ? 'imagine selectata' : 'imagini selectate' }}
                        </span>
                    @else
                        <span style="font-size: 0.875rem; color: #9ca3af; font-style: italic;">
                            Click pe imagini pentru a le selecta ca referinta
                        </span>
                    @endif
                </div>

                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    @if(count($this->selectedReferences) > 0)
                        <button
                            wire:click="$set('selectedReferences', [])"
                            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; background: #f3f4f6; color: #4b5563; font-weight: 500; border: none; cursor: pointer; transition: background 0.15s;"
                            onmouseover="this.style.background='#e5e7eb'"
                            onmouseout="this.style.background='#f3f4f6'"
                        >
                            Deselecteaza tot
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
                            <span wire:loading.remove wire:target="generateTemplates">🪄 Genereaza template-uri</span>
                            <span wire:loading wire:target="generateTemplates">⏳ Se analizeaza...</span>
                        </button>
                    @else
                        <button
                            disabled
                            style="background:#f3f4f6;color:#9ca3af;padding:6px 16px;border-radius:8px;font-size:14px;font-weight:600;border:none;cursor:not-allowed"
                        >
                            🪄 Genereaza template-uri
                        </button>
                    @endif
                </div>
            </div>

            {{-- Nota despre ce face butonul --}}
            @if(count($this->selectedReferences) > 0)
                <div style="margin-top: 0.75rem; padding: 0.75rem; border-radius: 0.5rem; background: #f5f3ff; border: 1px solid #ede9fe;">
                    <p style="font-size: 0.75rem; color: #6d28d9;">
                        <strong>Claude AI</strong> va analiza {{ count($this->selectedReferences) }} imagine(i),
                        va detecta structuri de layout recurente si va genera sabloane grafice refolosibile
                        in editorul de template-uri. Durata estimata: 15–30 secunde.
                    </p>
                </div>
            @endif
        @endif
    </div>

    {{-- Setari API --}}
    <div style="margin-top: 1.5rem; background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 1.25rem;">
        <h3 style="font-weight: 600; color: #1f2937; margin-bottom: 0.75rem;">Setari API</h3>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; font-size: 0.875rem;">
            <div>
                <p style="font-size: 0.75rem; color: #9ca3af; margin-bottom: 0.25rem;">Gemini API Key</p>
                <p style="font-family: monospace; font-size: 0.75rem; color: #4b5563;">
                    {{ \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_GEMINI_API_KEY) ? '✓ Setat' : '✗ Lipsa' }}
                </p>
            </div>
            <div>
                <p style="font-size: 0.75rem; color: #9ca3af; margin-bottom: 0.25rem;">Meta App ID</p>
                <p style="font-family: monospace; font-size: 0.75rem; color: #4b5563;">
                    {{ \App\Models\AppSetting::get(\App\Models\AppSetting::KEY_META_APP_ID) ?: '—' }}
                </p>
            </div>
            <div>
                <p style="font-size: 0.75rem; color: #9ca3af; margin-bottom: 0.25rem;">Meta App Secret</p>
                <p style="font-family: monospace; font-size: 0.75rem; color: #4b5563;">
                    {{ \App\Models\AppSetting::getEncrypted(\App\Models\AppSetting::KEY_META_APP_SECRET) ? '✓ Setat' : '—' }}
                </p>
            </div>
        </div>
    </div>

</x-filament-panels::page>
