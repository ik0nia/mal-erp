<x-filament-panels::page>

<div
    x-data="{
        selectedId: @entangle('selectedId'),
        attModal: false,
        attUrl: '',
        attName: '',
        attMime: '',
        selectEmail(id) {
            this.selectedId = id;
            document.getElementById('email-preview-frame').src = '/email-html/' + id;
            $wire.selectEmail(id);
        },
        openAtt(url, name, mime) {
            this.attUrl  = url;
            this.attName = name;
            this.attMime = mime;
            this.attModal = true;
        },
        closeAtt() {
            this.attModal = false;
            this.attUrl  = '';
        },
        init() {
            // Dacă există email preseceltat din PHP (mount), încarcă iframe-ul
            if (this.selectedId) {
                this.$nextTick(() => {
                    const frame = document.getElementById('email-preview-frame');
                    if (frame) frame.src = '/email-html/' + this.selectedId;
                });
            }
            // Urmărește selectedId și actualizează iframe la orice schimbare din PHP
            this.$watch('selectedId', val => {
                if (val) {
                    const frame = document.getElementById('email-preview-frame');
                    if (frame) frame.src = '/email-html/' + val;
                }
            });
        }
    }"
    @keydown.escape.window="closeAtt()"
    class="flex flex-col"
    style="height: calc(100vh - 10rem); min-height: 500px;"
>

    {{-- Search --}}
    <div class="flex items-center gap-2 mb-3 flex-shrink-0">
        <input
            wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="Caută subiect, expeditor..."
            class="flex-1 px-3 py-1.5 rounded-lg border border-gray-300 text-sm
                   dark:bg-gray-800 dark:border-gray-600 dark:text-gray-200
                   focus:outline-none focus:ring-2 focus:ring-primary-500"
        />
    </div>

    {{-- Layout 3 coloane --}}
    <div class="flex flex-1 min-h-0 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">

        {{-- COL 1: Foldere + filtre --}}
        <div class="w-44 flex-shrink-0 flex flex-col border-r border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-950">
            <div class="overflow-y-auto flex-1 min-h-0 py-2">

                <div class="px-2 mb-3">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-2 mb-1">Vizualizare</p>
                    @foreach(['all' => 'Toate', 'unread' => 'Necitite', 'read' => 'Citite'] as $val => $label)
                        <button
                            wire:click="setFilterRead('{{ $val }}')"
                            class="w-full text-left px-3 py-1.5 rounded-lg text-sm transition flex items-center justify-between
                                {{ $filterRead === $val
                                    ? 'bg-primary-600 text-white font-medium'
                                    : 'text-gray-600 hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                            <span>{{ $label }}</span>
                            @if($val === 'unread' && $this->getUnreadCount() > 0)
                                <span class="bg-red-500 text-white text-xs rounded-full px-1.5 leading-5">
                                    {{ $this->getUnreadCount() }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="px-2">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-2 mb-1">Foldere</p>
                    <button
                        wire:click="setFolder('')"
                        class="w-full text-left px-3 py-1.5 rounded-lg text-sm transition flex items-center justify-between
                            {{ $filterFolder === ''
                                ? 'bg-primary-600 text-white font-medium'
                                : 'text-gray-600 hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                        <span>Toate</span>
                    </button>
                    @foreach($this->getFolders() as $folder)
                        <button
                            wire:click="setFolder('{{ $folder['path'] }}')"
                            class="w-full text-left px-3 py-1.5 rounded-lg text-sm transition flex items-center justify-between
                                {{ $filterFolder === $folder['path']
                                    ? 'bg-primary-600 text-white font-medium'
                                    : 'text-gray-600 hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                            <span class="truncate">{{ $folder['label'] }}</span>
                            <span class="text-xs opacity-60 flex-shrink-0 ml-1">{{ $folder['total'] }}</span>
                        </button>
                    @endforeach
                </div>

            </div>
        </div>

        {{-- COL 2: Lista emailuri --}}
        <div class="w-72 flex-shrink-0 flex flex-col border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <div class="overflow-y-auto flex-1 min-h-0">
                @php $isSent = $filterFolder === 'INBOX.Sent'; @endphp
                @forelse($this->getEmails() as $email)
                    @php
                        // Pt emailuri trimise, afișăm destinatarul în loc de expeditor
                        $displayName = $isSent
                            ? (collect($email->to_recipients ?? [])->first()['name']
                                ?? collect($email->to_recipients ?? [])->first()['email']
                                ?? $email->from_email)
                            : ($email->from_name ?: $email->from_email);
                    @endphp
                    <button
                        @click="selectEmail({{ $email->id }})"
                        class="w-full text-left px-4 py-3 border-b border-gray-100 dark:border-gray-800 transition hover:bg-gray-50 dark:hover:bg-gray-800"
                        :class="selectedId === {{ $email->id }}
                            ? 'bg-primary-50 dark:bg-primary-900/30 border-l-4 border-l-primary-500'
                            : 'border-l-4 border-l-transparent'">

                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm truncate {{ $email->is_read ? 'font-normal text-gray-700 dark:text-gray-300' : 'font-bold text-gray-900 dark:text-gray-100' }}">
                                {{ $isSent ? 'Către: ' . $displayName : $displayName }}
                            </span>
                            <span class="text-xs text-gray-400 flex-shrink-0">
                                {{ $email->sent_at?->format('d.m H:i') ?? '-' }}
                            </span>
                        </div>

                        <div class="text-sm truncate mt-0.5 {{ $email->is_read ? 'font-normal text-gray-500' : 'font-semibold text-gray-800 dark:text-gray-200' }}">
                            {{ $email->subject ?: '(fără subiect)' }}
                        </div>

                        <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                            @if(! $email->is_read)
                                <span class="w-2 h-2 rounded-full bg-primary-500 flex-shrink-0"></span>
                            @endif
                            @if($email->is_flagged)
                                <span class="text-yellow-500 text-xs">★</span>
                            @endif
                            @if($filterFolder === '' && $email->imap_folder !== 'INBOX')
                                <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded px-1.5">
                                    {{ match(true) {
                                        $email->imap_folder === 'INBOX.Sent'         => 'Trimis',
                                        str_contains($email->imap_folder, 'Archive') => 'Arhivă',
                                        default => substr($email->imap_folder, strrpos($email->imap_folder, '.') + 1)
                                    } }}
                                </span>
                            @endif
                            @if($email->supplier)
                                <span class="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded px-1.5 truncate">
                                    {{ $email->supplier->name }}
                                </span>
                            @endif
                            @if($email->hasAttachments())
                                <span class="text-xs text-gray-400">📎</span>
                            @endif
                        </div>
                    </button>
                @empty
                    <div class="p-8 text-center text-gray-400 text-sm">
                        Nu există emailuri.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- COL 3: Conținut email --}}
        <div class="flex-1 flex flex-col min-w-0 bg-gray-50 dark:bg-gray-950">

            <div x-show="!selectedId" class="flex-1 flex flex-col items-center justify-center text-gray-400">
                <x-heroicon-o-envelope class="w-12 h-12 mb-3 opacity-30"/>
                <p class="text-sm">Selectează un email din listă</p>
            </div>

            <div x-show="selectedId" class="flex-1 flex flex-col min-h-0">

                @if($email = $this->getSelectedEmail())
                    <div class="flex-shrink-0 px-5 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-semibold text-gray-900 dark:text-white leading-snug">
                                    {{ $email->subject ?: '(fără subiect)' }}
                                </h2>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $email->from_label }}</span>
                                    &nbsp;·&nbsp;{{ $email->sent_at?->format('d.m.Y H:i') ?? '-' }}
                                    &nbsp;·&nbsp;<span class="text-gray-400">{{ $email->imap_folder }}</span>
                                </p>
                                @if($email->to_recipients)
                                    <p class="text-xs text-gray-400">
                                        Către: {{ collect($email->to_recipients)->map(fn($r) => $r['name'] ?? $r['email'])->implode(', ') }}
                                    </p>
                                @endif
                                @if($email->supplier)
                                    <span class="inline-block mt-1 text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded px-1.5 py-0.5">
                                        {{ $email->supplier->name }}
                                    </span>
                                @endif
                                @if($email->hasAttachments())
                                    <div class="mt-1.5 flex flex-wrap gap-1">
                                        @foreach($email->attachments as $i => $att)
                                            @php
                                                $isViewable = str_starts_with($att['mime_type'] ?? '', 'image/')
                                                    || ($att['mime_type'] ?? '') === 'application/pdf';
                                            @endphp
                                            @if($isViewable)
                                                <button
                                                    @click="openAtt('/email-attachment/{{ $email->id }}/{{ $i }}', '{{ addslashes($att['name']) }}', '{{ $att['mime_type'] ?? '' }}')"
                                                    class="text-xs bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-blue-600 dark:text-blue-400 rounded px-2 py-0.5 transition inline-flex items-center gap-1">
                                                    📎 {{ $att['name'] }}@if(isset($att['size'])) <span class="text-gray-400">({{ number_format($att['size'] / 1024, 1) }} KB)</span>@endif
                                                </button>
                                            @else
                                                <a href="/email-attachment/{{ $email->id }}/{{ $i }}" download="{{ $att['name'] }}"
                                                   class="text-xs bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-blue-600 dark:text-blue-400 rounded px-2 py-0.5 transition inline-flex items-center gap-1">
                                                    📎 {{ $att['name'] }}@if(isset($att['size'])) <span class="text-gray-400">({{ number_format($att['size'] / 1024, 1) }} KB)</span>@endif
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                @if($email->internal_notes)
                                    <p class="mt-1 text-xs text-yellow-700 dark:text-yellow-300">
                                        <span class="font-medium">Notă:</span> {{ $email->internal_notes }}
                                    </p>
                                @endif
                            </div>
                            <button wire:click="toggleFlag({{ $email->id }})" title="Marchează"
                                    class="text-xl flex-shrink-0 {{ $email->is_flagged ? 'text-yellow-400' : 'text-gray-300 hover:text-yellow-400' }} transition">
                                ★
                            </button>
                        </div>
                    </div>
                @endif

                <div class="flex-1 min-h-0 relative">
                    <iframe
                        id="email-preview-frame"
                        wire:ignore
                        src="about:blank"
                        class="absolute inset-0 w-full h-full border-0 bg-white"
                        sandbox="allow-same-origin allow-popups"
                        referrerpolicy="no-referrer"
                    ></iframe>
                </div>

            </div>
        </div>
    </div>

    {{-- Modal atașament --}}
    <div x-show="attModal" x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">

        <div class="absolute inset-0 bg-black/60" @click="closeAtt()"></div>

        <div class="relative z-10 bg-white dark:bg-gray-900 rounded-xl shadow-2xl flex flex-col overflow-hidden"
             style="width: min(92vw, 1100px); height: min(92vh, 850px);">

            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200 truncate" x-text="attName"></span>
                <div class="flex items-center gap-2 flex-shrink-0 ml-3">
                    <a :href="attUrl" :download="attName"
                       class="text-xs px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 transition">
                        ⬇ Descarcă
                    </a>
                    <button @click="closeAtt()"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-xl leading-none px-1 transition">✕</button>
                </div>
            </div>

            <div class="flex-1 min-h-0 bg-gray-100 dark:bg-gray-950">
                <template x-if="attMime === 'application/pdf'">
                    <iframe :src="attUrl" class="w-full h-full border-0"></iframe>
                </template>
                <template x-if="attMime.startsWith('image/')">
                    <div class="flex items-center justify-center w-full h-full p-6">
                        <img :src="attUrl" :alt="attName" class="max-w-full max-h-full object-contain rounded shadow-lg">
                    </div>
                </template>
            </div>
        </div>
    </div>

</div>

</x-filament-panels::page>
