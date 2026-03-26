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
    style="display: flex; flex-direction: column; height: calc(100vh - 10rem); min-height: 500px;"
>

    {{-- Search + filtre AI --}}
    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem; flex-shrink: 0;">
        <input
            wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="Cauta subiect, expeditor..."
            style="width: 100%; padding: 0.375rem 0.75rem; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; outline: none;"
        />
        @php $aiCounts = $this->getAiFilterCounts(); @endphp
        <div style="display: flex; flex-wrap: wrap; gap: 0.375rem; align-items: center;">
            <span style="font-size: 0.75rem; color: #9ca3af; margin-right: 0.25rem;">AI:</span>

            <button wire:click="toggleNeedsReply"
                style="font-size: 0.75rem; padding: 0.25rem 0.625rem; border-radius: 9999px; border: 1px solid {{ $filterNeedsReply ? '#ef4444' : '#d1d5db' }}; transition: all 0.15s; cursor: pointer; {{ $filterNeedsReply ? 'background: #ef4444; color: #fff;' : 'background: transparent; color: #4b5563;' }}">
                Necesita raspuns @if($aiCounts['needs_reply'] > 0)<span style="margin-left: 0.25rem; opacity: 0.75;">{{ $aiCounts['needs_reply'] }}</span>@endif
            </button>

            <button wire:click="setFilterUrgency('high')"
                style="font-size: 0.75rem; padding: 0.25rem 0.625rem; border-radius: 9999px; border: 1px solid {{ $filterUrgency === 'high' ? '#f97316' : '#d1d5db' }}; transition: all 0.15s; cursor: pointer; {{ $filterUrgency === 'high' ? 'background: #f97316; color: #fff;' : 'background: transparent; color: #4b5563;' }}">
                🔴 Urgent @if($aiCounts['high'] > 0)<span style="margin-left: 0.25rem; opacity: 0.75;">{{ $aiCounts['high'] }}</span>@endif
            </button>

            @php
            $typeFilters = [
                'offer'      => ['label' => 'Oferte',       'color' => '#22c55e'],
                'invoice'    => ['label' => 'Facturi',      'color' => '#eab308'],
                'price_list' => ['label' => 'Liste preturi','color' => '#a855f7'],
            ];
            @endphp
            @foreach($typeFilters as $type => $cfg)
            <button wire:click="setFilterType('{{ $type }}')"
                style="font-size: 0.75rem; padding: 0.25rem 0.625rem; border-radius: 9999px; border: 1px solid {{ $filterType === $type ? $cfg['color'] : '#d1d5db' }}; transition: all 0.15s; cursor: pointer; {{ $filterType === $type ? 'background: ' . $cfg['color'] . '; color: #fff;' : 'background: transparent; color: #4b5563;' }}">
                {{ $cfg['label'] }} @if(($aiCounts[$type] ?? 0) > 0)<span style="margin-left: 0.25rem; opacity: 0.75;">{{ $aiCounts[$type] }}</span>@endif
            </button>
            @endforeach

            @if($filterType || $filterUrgency || $filterNeedsReply)
            <button wire:click="$set('filterType', ''); $set('filterUrgency', ''); $set('filterNeedsReply', false)"
                style="font-size: 0.75rem; padding: 0.25rem 0.5rem; border-radius: 9999px; color: #9ca3af; background: transparent; border: none; cursor: pointer; transition: color 0.15s;">
                ✕ Reseteaza
            </button>
            @endif
        </div>
    </div>

    {{-- Layout 3 coloane --}}
    <div style="display: flex; flex: 1; min-height: 0; border-radius: 0.75rem; border: 1px solid #e5e7eb; overflow: hidden;">

        {{-- COL 1: Foldere + filtre --}}
        <div style="width: 11rem; flex-shrink: 0; display: flex; flex-direction: column; border-right: 1px solid #e5e7eb; background: #f9fafb;">
            <div style="overflow-y: auto; flex: 1; min-height: 0; padding: 0.5rem 0;">

                <div style="padding: 0 0.5rem; margin-bottom: 0.75rem;">
                    <p style="font-size: 0.75rem; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; padding: 0 0.5rem; margin-bottom: 0.25rem;">Vizualizare</p>
                    @foreach(['all' => 'Toate', 'unread' => 'Necitite', 'read' => 'Citite'] as $val => $label)
                        <button
                            wire:click="setFilterRead('{{ $val }}')"
                            style="width: 100%; text-align: left; padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: all 0.15s; display: flex; align-items: center; justify-content: space-between; border: none; cursor: pointer; {{ $filterRead === $val ? 'background: #8B1A1A; color: #fff; font-weight: 500;' : 'background: transparent; color: #4b5563;' }}">
                            <span>{{ $label }}</span>
                            @if($val === 'unread' && $this->getUnreadCount() > 0)
                                <span style="background: #ef4444; color: #fff; font-size: 0.75rem; border-radius: 9999px; padding: 0 0.375rem; line-height: 1.25rem;">
                                    {{ $this->getUnreadCount() }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div style="padding: 0 0.5rem;">
                    <p style="font-size: 0.75rem; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; padding: 0 0.5rem; margin-bottom: 0.25rem;">Foldere</p>
                    <button
                        wire:click="setFolder('')"
                        style="width: 100%; text-align: left; padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: all 0.15s; display: flex; align-items: center; justify-content: space-between; border: none; cursor: pointer; {{ $filterFolder === '' ? 'background: #8B1A1A; color: #fff; font-weight: 500;' : 'background: transparent; color: #4b5563;' }}">
                        <span>Toate</span>
                    </button>
                    @foreach($this->getFolders() as $folder)
                        <button
                            wire:click="setFolder('{{ $folder['path'] }}')"
                            style="width: 100%; text-align: left; padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; transition: all 0.15s; display: flex; align-items: center; justify-content: space-between; border: none; cursor: pointer; {{ $filterFolder === $folder['path'] ? 'background: #8B1A1A; color: #fff; font-weight: 500;' : 'background: transparent; color: #4b5563;' }}">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $folder['label'] }}</span>
                            <span style="font-size: 0.75rem; opacity: 0.6; flex-shrink: 0; margin-left: 0.25rem;">{{ $folder['total'] }}</span>
                        </button>
                    @endforeach
                </div>

            </div>
        </div>

        {{-- COL 2: Lista emailuri --}}
        <div style="width: 18rem; flex-shrink: 0; display: flex; flex-direction: column; border-right: 1px solid #e5e7eb; background: #fff;">
            <div style="overflow-y: auto; flex: 1; min-height: 0;">
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
                        style="width: 100%; text-align: left; padding: 0.75rem 1rem; border-bottom: 1px solid #f3f4f6; transition: background 0.15s; border-left: 4px solid transparent; background: transparent; cursor: pointer; border-right: none; border-top: none;"
                        :style="selectedId === {{ $email->id }}
                            ? 'background: #fdf2f2; border-left: 4px solid #8B1A1A;'
                            : 'border-left: 4px solid transparent;'">

                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem;">
                            <span style="font-size: 0.875rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; {{ $email->is_read ? 'font-weight: 400; color: #374151;' : 'font-weight: 700; color: #111827;' }}">
                                {{ $isSent ? 'Catre: ' . $displayName : $displayName }}
                            </span>
                            <span style="font-size: 0.75rem; color: #9ca3af; flex-shrink: 0;">
                                {{ $email->sent_at?->format('d.m H:i') ?? '-' }}
                            </span>
                        </div>

                        <div style="font-size: 0.875rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 0.125rem; {{ $email->is_read ? 'font-weight: 400; color: #6b7280;' : 'font-weight: 600; color: #1f2937;' }}">
                            {{ $email->subject ?: '(fara subiect)' }}
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.375rem; margin-top: 0.25rem; flex-wrap: wrap;">
                            @if(! $email->is_read)
                                <span style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #8B1A1A; flex-shrink: 0;"></span>
                            @endif
                            @if($email->is_flagged)
                                <span style="color: #eab308; font-size: 0.75rem;">★</span>
                            @endif
                            @if($filterFolder === '' && $email->imap_folder !== 'INBOX')
                                <span style="font-size: 0.75rem; background: #f3f4f6; color: #6b7280; border-radius: 0.25rem; padding: 0 0.375rem;">
                                    {{ match(true) {
                                        $email->imap_folder === 'INBOX.Sent'         => 'Trimis',
                                        str_contains($email->imap_folder, 'Archive') => 'Arhiva',
                                        default => substr($email->imap_folder, strrpos($email->imap_folder, '.') + 1)
                                    } }}
                                </span>
                            @endif
                            @if($email->supplier)
                                <span style="font-size: 0.75rem; background: #dbeafe; color: #1d4ed8; border-radius: 0.25rem; padding: 0 0.375rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80px;">
                                    {{ $email->supplier->name }}
                                </span>
                            @endif
                            @if($email->agent_actions)
                                @php
                                    $t = $email->agent_actions['type'] ?? null;
                                    $u = $email->agent_actions['urgency'] ?? null;
                                    $nr = !empty($email->agent_actions['needs_reply']);
                                    $typeBadge = match($t) {
                                        'offer'                 => ['Oferta',  'background: #dcfce7; color: #15803d;'],
                                        'invoice'               => ['Fact.',   'background: #fef9c3; color: #a16207;'],
                                        'price_list'            => ['Preturi', 'background: #f3e8ff; color: #7e22ce;'],
                                        'order_confirmation'    => ['Conf.',   'background: #dbeafe; color: #1d4ed8;'],
                                        'delivery_notification' => ['Livrare', 'background: #e0f2fe; color: #0369a1;'],
                                        default => null,
                                    };
                                @endphp
                                @if($typeBadge)
                                    <span style="font-size: 0.75rem; border-radius: 0.25rem; padding: 0 0.375rem; {{ $typeBadge[1] }}">
                                        {{ $typeBadge[0] }}
                                    </span>
                                @endif
                                @if($u === 'high')
                                    <span style="font-size: 0.75rem; color: #ef4444;" title="Urgent">🔴</span>
                                @endif
                                @if($nr)
                                    <span style="font-size: 0.75rem; color: #f97316;" title="Necesita raspuns">↩</span>
                                @endif
                            @endif
                            @if($email->hasAttachments())
                                <span style="font-size: 0.75rem; color: #9ca3af;">📎</span>
                            @endif
                        </div>
                    </button>
                @empty
                    <div style="padding: 2rem; text-align: center; color: #9ca3af; font-size: 0.875rem;">
                        Nu exista emailuri.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- COL 3: Continut email --}}
        <div style="flex: 1; display: flex; flex-direction: column; min-width: 0; background: #f9fafb;">

            <div x-show="!selectedId" style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #9ca3af;">
                <x-filament::icon icon="heroicon-o-envelope" style="width: 3rem; height: 3rem; margin-bottom: 0.75rem; opacity: 0.3;"/>
                <p style="font-size: 0.875rem;">Selecteaza un email din lista</p>
            </div>

            <div x-show="selectedId" style="flex: 1; display: flex; flex-direction: column; min-height: 0;">

                @if($email = $this->getSelectedEmail())
                    <div style="flex-shrink: 0; padding: 0.75rem 1.25rem; background: #fff; border-bottom: 1px solid #e5e7eb;">
                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;">
                            <div style="min-width: 0; flex: 1;">
                                <h2 style="font-size: 0.875rem; font-weight: 600; color: #111827; line-height: 1.375;">
                                    {{ $email->subject ?: '(fara subiect)' }}
                                </h2>
                                <p style="margin-top: 0.125rem; font-size: 0.75rem; color: #6b7280;">
                                    <span style="font-weight: 500; color: #374151;">{{ $email->from_label }}</span>
                                    &nbsp;·&nbsp;{{ $email->sent_at?->format('d.m.Y H:i') ?? '-' }}
                                    &nbsp;·&nbsp;<span style="color: #9ca3af;">{{ $email->imap_folder }}</span>
                                </p>
                                @if($email->to_recipients)
                                    <p style="font-size: 0.75rem; color: #9ca3af;">
                                        Catre: {{ collect($email->to_recipients)->map(fn($r) => $r['name'] ?? $r['email'])->implode(', ') }}
                                    </p>
                                @endif
                                @if($email->supplier)
                                    <span style="display: inline-block; margin-top: 0.25rem; font-size: 0.75rem; background: #dbeafe; color: #1d4ed8; border-radius: 0.25rem; padding: 0 0.375rem 0.125rem;">
                                        {{ $email->supplier->name }}
                                    </span>
                                @endif
                                @if($email->hasAttachments())
                                    <div style="margin-top: 0.375rem; display: flex; flex-wrap: wrap; gap: 0.25rem;">
                                        @foreach($email->attachments as $i => $att)
                                            @php
                                                $isViewable = str_starts_with($att['mime_type'] ?? '', 'image/')
                                                    || ($att['mime_type'] ?? '') === 'application/pdf';
                                            @endphp
                                            @if($isViewable)
                                                <button
                                                    @click="openAtt('/email-attachment/{{ $email->id }}/{{ $i }}', '{{ addslashes($att['name']) }}', '{{ $att['mime_type'] ?? '' }}')"
                                                    style="font-size: 0.75rem; background: #f3f4f6; color: #2563eb; border-radius: 0.25rem; padding: 0.125rem 0.5rem; transition: background 0.15s; display: inline-flex; align-items: center; gap: 0.25rem; border: none; cursor: pointer;"
                                                    onmouseover="this.style.background='#e5e7eb'"
                                                    onmouseout="this.style.background='#f3f4f6'">
                                                    📎 {{ $att['name'] }}@if(isset($att['size'])) <span style="color: #9ca3af;">({{ number_format($att['size'] / 1024, 1) }} KB)</span>@endif
                                                </button>
                                            @else
                                                <a href="/email-attachment/{{ $email->id }}/{{ $i }}" download="{{ $att['name'] }}"
                                                   style="font-size: 0.75rem; background: #f3f4f6; color: #2563eb; border-radius: 0.25rem; padding: 0.125rem 0.5rem; transition: background 0.15s; display: inline-flex; align-items: center; gap: 0.25rem; text-decoration: none;"
                                                   onmouseover="this.style.background='#e5e7eb'"
                                                   onmouseout="this.style.background='#f3f4f6'">
                                                    📎 {{ $att['name'] }}@if(isset($att['size'])) <span style="color: #9ca3af;">({{ number_format($att['size'] / 1024, 1) }} KB)</span>@endif
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                @if($email->internal_notes)
                                    <p style="margin-top: 0.25rem; font-size: 0.75rem; color: #a16207;">
                                        <span style="font-weight: 500;">Nota:</span> {{ $email->internal_notes }}
                                    </p>
                                @endif
                            </div>
                            <button wire:click="toggleFlag({{ $email->id }})" title="Marcheaza"
                                    style="font-size: 1.25rem; flex-shrink: 0; background: none; border: none; cursor: pointer; transition: color 0.15s; {{ $email->is_flagged ? 'color: #facc15;' : 'color: #d1d5db;' }}">
                                ★
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Panou AI analiza --}}
                @if($email && $email->agent_actions)
                @php
                    $ai = $email->agent_actions;
                    $typeInfo = match($ai['type'] ?? '') {
                        'offer'                 => ['label' => 'Oferta',            'style' => 'background: #dcfce7; color: #15803d;'],
                        'invoice'               => ['label' => 'Factura',           'style' => 'background: #fef9c3; color: #a16207;'],
                        'order_confirmation'    => ['label' => 'Confirmare comanda','style' => 'background: #dbeafe; color: #1d4ed8;'],
                        'delivery_notification' => ['label' => 'Notif. livrare',    'style' => 'background: #e0f2fe; color: #0369a1;'],
                        'price_list'            => ['label' => 'Lista preturi',     'style' => 'background: #f3e8ff; color: #7e22ce;'],
                        'payment'               => ['label' => 'Plata',             'style' => 'background: #d1fae5; color: #047857;'],
                        'complaint'             => ['label' => 'Reclamatie',        'style' => 'background: #fee2e2; color: #b91c1c;'],
                        'inquiry'               => ['label' => 'Informare',         'style' => 'background: #f3f4f6; color: #4b5563;'],
                        'automated'             => ['label' => 'Automat',           'style' => 'background: #f3f4f6; color: #6b7280;'],
                        default                 => ['label' => $ai['type'] ?? '?',  'style' => 'background: #f3f4f6; color: #6b7280;'],
                    };
                    $urgency = $ai['urgency'] ?? 'low';
                    $urgStyle = ['high' => 'background: #fee2e2; color: #b91c1c;', 'medium' => 'background: #ffedd5; color: #c2410c;'];
                    $urgLabel = ['high' => 'Urgent', 'medium' => 'Mediu'];
                @endphp
                <div style="flex-shrink: 0; padding: 0.75rem 1.25rem; background: #fdf2f2; border-bottom: 1px solid #e8c4c4;">
                    <div style="display: flex; align-items: flex-start; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                        {{-- Tip --}}
                        <span style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-weight: 500; {{ $typeInfo['style'] }}">
                            {{ $typeInfo['label'] }}
                        </span>
                        {{-- Urgenta --}}
                        @if(isset($urgStyle[$urgency]))
                        <span style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-weight: 500; {{ $urgStyle[$urgency] }}">
                            {{ $urgLabel[$urgency] }}
                        </span>
                        @endif
                        {{-- Needs reply --}}
                        @if(!empty($ai['needs_reply']))
                        <span style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-weight: 500; background: #fee2e2; color: #b91c1c;">
                            ↩ Necesita raspuns
                        </span>
                        @endif
                        {{-- Sentiment --}}
                        @if(!empty($ai['sentiment']) && $ai['sentiment'] !== 'neutral')
                        <span style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; {{ $ai['sentiment'] === 'positive' ? 'background: #dcfce7; color: #16a34a;' : 'background: #fee2e2; color: #dc2626;' }}">
                            {{ $ai['sentiment'] === 'positive' ? '😊 Pozitiv' : '😟 Negativ' }}
                        </span>
                        @endif
                    </div>

                    {{-- Summary --}}
                    @if(!empty($ai['summary']))
                    <p style="font-size: 0.75rem; color: #374151; line-height: 1.625; margin-bottom: 0.5rem;">
                        {{ $ai['summary'] }}
                    </p>
                    @endif

                    <div style="display: flex; flex-wrap: wrap; column-gap: 1.5rem; row-gap: 0.25rem; font-size: 0.75rem; color: #6b7280;">
                        @if(!empty($ai['invoice_number']))
                            <span>📄 Factura: <span style="font-weight: 500; color: #374151;">{{ $ai['invoice_number'] }}</span></span>
                        @endif
                        @if(!empty($ai['delivery_date']))
                            <span>🚚 Livrare: <span style="font-weight: 500; color: #374151;">{{ $ai['delivery_date'] }}</span></span>
                        @endif
                        @if(!empty($ai['payment_terms']))
                            <span>💳 Plata: <span style="font-weight: 500; color: #374151;">{{ $ai['payment_terms'] }}</span></span>
                        @endif
                        @if(!empty($ai['discount_mentioned']))
                            <span>🏷️ Reducere: <span style="font-weight: 500; color: #374151;">{{ $ai['discount_mentioned'] }}</span></span>
                        @endif
                        @if(!empty($ai['key_info']))
                            <span>🔑 <span style="color: #374151;">{{ $ai['key_info'] }}</span></span>
                        @endif
                    </div>

                    @if(!empty($ai['prices_mentioned']))
                    <div style="margin-top: 0.5rem; display: flex; flex-wrap: wrap; gap: 0.375rem;">
                        @foreach(array_slice($ai['prices_mentioned'], 0, 4) as $price)
                        <span style="font-size: 0.75rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 0.25rem; padding: 0.125rem 0.5rem; color: #374151;">
                            {{ $price['product'] ?? '?' }}: <span style="font-weight: 600;">{{ $price['price'] }} {{ $price['currency'] ?? 'RON' }}</span>
                        </span>
                        @endforeach
                        @if(count($ai['prices_mentioned']) > 4)
                            <span style="font-size: 0.75rem; color: #9ca3af;">+{{ count($ai['prices_mentioned']) - 4 }} preturi</span>
                        @endif
                    </div>
                    @endif

                    @if(!empty($ai['action_items']))
                    <div style="margin-top: 0.5rem;">
                        @foreach(array_slice($ai['action_items'], 0, 3) as $item)
                        <p style="font-size: 0.75rem; color: #8B1A1A;">→ {{ $item }}</p>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif

                <div style="flex: 1; min-height: 0; position: relative;">
                    <iframe
                        id="email-preview-frame"
                        wire:ignore
                        src="about:blank"
                        style="position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: #fff;"
                        sandbox="allow-same-origin allow-popups"
                        referrerpolicy="no-referrer"
                    ></iframe>
                </div>

            </div>
        </div>
    </div>

    {{-- Modal atasament --}}
    <div x-show="attModal" x-transition.opacity
         style="display: none; position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 1rem;">

        <div style="position: absolute; inset: 0; background: rgba(0,0,0,0.6);" @click="closeAtt()"></div>

        <div style="position: relative; z-index: 10; background: #fff; border-radius: 0.75rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; width: min(92vw, 1100px); height: min(92vh, 850px);">

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
                <span style="font-size: 0.875rem; font-weight: 500; color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="attName"></span>
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; margin-left: 0.75rem;">
                    <a :href="attUrl" :download="attName"
                       style="font-size: 0.75rem; padding: 0.375rem 0.75rem; border-radius: 0.5rem; background: #f3f4f6; color: #4b5563; transition: background 0.15s; text-decoration: none;"
                       onmouseover="this.style.background='#e5e7eb'"
                       onmouseout="this.style.background='#f3f4f6'">
                        ⬇ Descarca
                    </a>
                    <button @click="closeAtt()"
                            style="color: #9ca3af; font-size: 1.25rem; line-height: 1; padding: 0 0.25rem; transition: color 0.15s; background: none; border: none; cursor: pointer;">✕</button>
                </div>
            </div>

            <div style="flex: 1; min-height: 0; background: #f3f4f6;">
                <template x-if="attMime === 'application/pdf'">
                    <iframe :src="attUrl" style="width: 100%; height: 100%; border: 0;"></iframe>
                </template>
                <template x-if="attMime.startsWith('image/')">
                    <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; padding: 1.5rem;">
                        <img :src="attUrl" :alt="attName" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 0.25rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);">
                    </div>
                </template>
            </div>
        </div>
    </div>

</div>

</x-filament-panels::page>
