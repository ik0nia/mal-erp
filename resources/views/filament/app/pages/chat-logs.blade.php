<x-filament-panels::page>

{{-- Culori Tailwind lipsă din CSS compilat (build nu a inclus aceste clase) --}}
<style>
.bg-orange-100{background-color:#ffedd5}.bg-orange-500{background-color:#f97316}
.text-orange-600{color:#ea580c}.rounded-br-sm{border-bottom-right-radius:2px}.rounded-bl-sm{border-bottom-left-radius:2px}
.bg-red-50{background-color:#fef2f2}.bg-red-100{background-color:#fee2e2}.bg-red-500{background-color:#ef4444}
.text-red-600{color:#dc2626}.text-red-700{color:#b91c1c}.text-red-800{color:#991b1b}
.border-red-200{border-color:#fecaca}.bg-green-500{background-color:#22c55e}.hover\:bg-green-600:hover{background-color:#16a34a}
.bg-indigo-50{background-color:#eef2ff}.border-indigo-100{border-color:#e0e7ff}
.text-indigo-400{color:#818cf8}.text-indigo-500{color:#6366f1}.text-indigo-700{color:#4338ca}
.text-amber-500{color:#f59e0b}.text-amber-700{color:#b45309}.text-amber-800{color:#92400e}
.bg-amber-50{background-color:#fffbeb}.bg-blue-100{background-color:#dbeafe}
.text-blue-600{color:#2563eb}.text-blue-700{color:#1d4ed8}
.border-blue-200{border-color:#bfdbfe}
.text-emerald-700{color:#047857}.border-emerald-200{border-color:#a7f3d0}
.bg-green-100{background-color:#dcfce7}.text-green-700{color:#15803d}
/* dark mode */
.dark .bg-orange-900\/30{background-color:rgba(124,45,18,.3)}.dark .text-orange-400{color:#fb923c}
.dark .bg-red-900\/40{background-color:rgba(153,27,27,.4)}.dark .text-red-300{color:#fca5a5}
.dark .bg-indigo-950\/30{background-color:rgba(30,27,75,.3)}.dark .border-indigo-900\/40{border-color:rgba(49,46,129,.4)}
.dark .text-indigo-300{color:#a5b4fc}.dark .text-indigo-400{color:#818cf8}
.dark .text-amber-300{color:#fcd34d}.dark .text-amber-400{color:#fbbf24}
.dark .bg-amber-900\/20{background-color:rgba(120,53,15,.2)}
.dark .text-blue-400{color:#60a5fa}.dark .bg-blue-900\/30{background-color:rgba(30,58,138,.3)}
.dark .border-blue-800{border-color:#1e40af}
.dark .text-emerald-400{color:#34d399}.dark .bg-emerald-900\/30{background-color:rgba(6,78,59,.3)}.dark .border-emerald-800{border-color:#065f46}
.dark .bg-green-900\/30{background-color:rgba(20,83,45,.3)}.dark .text-green-400{color:#4ade80}
</style>

{{-- Auto-refresh la 30 secunde --}}
<div wire:poll.30s class="space-y-5">

    {{-- Stat cards --}}
    <div class="grid grid-cols-3 gap-4">
        @foreach([
            ['label' => 'Sesiuni totale', 'value' => $this->getTotalSessions()],
            ['label' => 'Sesiuni azi',    'value' => $this->getTodaySessions()],
            ['label' => 'Mesaje azi',     'value' => $this->getTodayMessages()],
        ] as $card)
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($card['value']) }}</p>
        </div>
        @endforeach
    </div>

    {{-- Lista sesiuni --}}
    @php $sessions = $this->getSessions(); @endphp

    @if($sessions->isEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-10 text-center text-gray-400">
        Nu există conversații înregistrate încă.
    </div>
    @else

    <div class="space-y-3">
    @foreach($sessions as $session)

    {{-- Sesiune — Alpine.js toggle, fără round-trip server --}}
    <div
        id="session-{{ $session->session_id }}"
        x-data="{ open: false }"
        class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
    >
        {{-- Header sesiune (click toggle) --}}
        <div
            @click="open = !open"
            class="flex items-start gap-4 px-5 py-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors select-none"
        >
            <div class="flex-shrink-0 mt-0.5">
                <div class="w-8 h-8 rounded-full bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                    <x-filament::icon icon="heroicon-o-user" class="w-4 h-4 text-orange-600 dark:text-orange-400"/>
                </div>
            </div>

            <div class="flex-1 min-w-0">
                {{-- Rând principal: preview mesaj + badge specialist (vizibil imediat) --}}
                <div class="flex items-center gap-2">
                    <p class="text-sm text-gray-800 dark:text-gray-200 truncate font-medium flex-1">{{ $session->first_message }}</p>
                    @if($session->wants_specialist)
                    <span class="flex-shrink-0 inline-flex items-center gap-1 text-xs font-semibold bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 px-2 py-0.5 rounded-full">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                        Vrea specialist
                    </span>
                    @endif
                </div>
                {{-- Lead card: contact + rezumat AI --}}
                @if($session->contact_email || $session->contact_phone || $session->summary)
                <div class="mt-2 rounded-lg border border-indigo-100 dark:border-indigo-900/40 bg-indigo-50 dark:bg-indigo-950/30 px-3 py-2 space-y-1.5">

                    {{-- Date contact --}}
                    @if($session->contact_email || $session->contact_phone)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-indigo-500 dark:text-indigo-400 uppercase tracking-wide">Lead</span>
                        @if($session->contact_email)
                        <a href="mailto:{{ $session->contact_email }}"
                           class="inline-flex items-center gap-1 text-xs font-medium text-blue-700 dark:text-blue-400 bg-white dark:bg-blue-900/30 px-2 py-0.5 rounded border border-blue-200 dark:border-blue-800 hover:underline">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 6l-10 7L2 6"/></svg>
                            {{ $session->contact_email }}
                        </a>
                        @endif
                        @if($session->contact_phone)
                        <a href="tel:{{ $session->contact_phone }}"
                           class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400 bg-white dark:bg-emerald-900/30 px-2 py-0.5 rounded border border-emerald-200 dark:border-emerald-800 hover:underline">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8a19.79 19.79 0 01-3.07-8.67A2 2 0 012 .99h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 8.84a16 16 0 006.07 6.07l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                            {{ $session->contact_phone }}
                        </a>
                        @endif
                        @if($session->wants_specialist)
                        <span class="inline-flex items-center gap-1 text-xs font-semibold bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 px-2 py-0.5 rounded-full">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                            Vrea specialist
                        </span>
                        @endif
                    </div>
                    @endif

                    {{-- Interes produse --}}
                    @if($session->interested_in)
                    <div class="flex items-start gap-1.5">
                        <svg class="w-3.5 h-3.5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        <span class="text-xs text-amber-800 dark:text-amber-300 font-medium">{{ $session->interested_in }}</span>
                    </div>
                    @endif

                    {{-- Rezumat AI --}}
                    @if($session->summary)
                    <div class="flex items-start gap-1.5">
                        <x-filament::icon icon="heroicon-o-cpu-chip" class="w-3.5 h-3.5 text-indigo-400 flex-shrink-0 mt-0.5"/>
                        <span class="text-xs text-indigo-700 dark:text-indigo-300 italic">{{ $session->summary }}</span>
                    </div>
                    @endif

                </div>
                @endif
                {{-- Status contactat / buton marcare --}}
                @if($session->contact_email || $session->contact_phone)
                <div class="mt-2 flex items-center gap-2">
                    @if($session->contacted_at)
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-green-700 bg-green-100 dark:bg-green-900/30 dark:text-green-400 px-2.5 py-1 rounded-full">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                        Contactat de {{ $session->contacted_by_name ?? 'cineva' }}
                        · {{ \Carbon\Carbon::parse($session->contacted_at)->format('d.m H:i') }}
                    </span>
                    @else
                    <button
                        wire:click.stop="markAsContacted('{{ $session->session_id }}')"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg transition-colors"
                    >
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                        Marchează contactat
                    </button>
                    @endif
                </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 mt-1">
                    <span class="text-xs text-gray-400">
                        {{ \Carbon\Carbon::parse($session->started_at)->format('d.m.Y H:i') }}
                    </span>
                    <span class="text-xs text-gray-300">·</span>
                    <span class="text-xs text-gray-400">{{ $session->user_messages }} {{ Str::plural('întrebare', $session->user_messages) }}</span>
                    @if($session->ip_address)
                    <span class="text-xs text-gray-300">·</span>
                    <span class="text-xs text-gray-400 font-mono">{{ $session->ip_address }}</span>
                    @endif
                    @if($session->had_products)
                    <span class="text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-1.5 py-0.5 rounded">produse afișate</span>
                    @endif
                    @php $cost = $this->formatCost((int)$session->total_input_tokens, (int)$session->total_output_tokens); @endphp
                    @if($cost)
                    <span class="text-xs bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 px-1.5 py-0.5 rounded font-mono"
                          title="{{ $session->total_input_tokens }} tokeni intrare / {{ $session->total_output_tokens }} tokeni ieșire">{{ $cost }}</span>
                    @endif
                    {{-- Ora ultimului mesaj dacă e azi --}}
                    @if(\Carbon\Carbon::parse($session->last_at)->isToday() && $session->total_messages > 2)
                    <span class="text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-1.5 py-0.5 rounded">
                        ultima: {{ \Carbon\Carbon::parse($session->last_at)->format('H:i') }}
                    </span>
                    @endif
                    {{-- Pagina de start --}}
                    @if($session->first_page_url)
                    <a href="{{ $session->first_page_url }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                       title="{{ $session->first_page_url }}">
                        <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        {{ $session->first_page_title ?: parse_url($session->first_page_url, PHP_URL_PATH) ?: $session->first_page_url }}
                    </a>
                    @endif
                </div>
            </div>

            <div class="flex-shrink-0 mt-1 text-gray-400">
                <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                <svg x-show="open"  xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 15l7-7 7 7"/></svg>
            </div>
        </div>

        {{-- Conversație (toggle cu Alpine) --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/30 px-5 pb-5 pt-4 space-y-3"
        >
            {{-- Pagini vizitate (unice, în ordine) --}}
            @php
                $visitedPages = $session->messages
                    ->where('role', 'user')
                    ->whereNotNull('page_url')
                    ->unique('page_url')
                    ->values();
            @endphp
            @if($visitedPages->isNotEmpty())
            <div class="mb-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    Pagini vizitate ({{ $visitedPages->count() }})
                </p>
                <ol class="space-y-1">
                    @foreach($visitedPages as $i => $pMsg)
                    <li class="flex items-start gap-2">
                        <span class="flex-shrink-0 text-[10px] font-bold text-gray-300 dark:text-gray-600 w-4 text-right mt-0.5">{{ $i + 1 }}</span>
                        <div class="min-w-0">
                            @if($pMsg->page_title)
                            <p class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate">{{ $pMsg->page_title }}</p>
                            @endif
                            <a href="{{ $pMsg->page_url }}" target="_blank" rel="noopener"
                               class="text-[11px] font-mono text-blue-600 dark:text-blue-400 hover:underline break-all">
                                {{ $pMsg->page_url }}
                            </a>
                        </div>
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif

            @foreach($session->messages as $msg)
            <div class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-xl min-w-0">
                    <p class="text-xs text-gray-400 mb-1 {{ $msg->role === 'user' ? 'text-right' : '' }}">
                        @if($msg->role === 'user')
                            Client
                        @else
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-cpu-chip" class="w-3 h-3"/>
                                Bot
                            </span>
                        @endif
                        · {{ \Carbon\Carbon::parse($msg->created_at)->format('H:i:s') }}
                        @if($msg->has_products)
                            · <span class="text-green-600 dark:text-green-400">cu produse</span>
                        @endif
                    </p>
                    <div class="rounded-xl px-4 py-2.5 text-sm whitespace-pre-wrap break-words
                        {{ $msg->role === 'user'
                            ? 'bg-orange-500 text-white rounded-br-sm'
                            : 'bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-600 rounded-bl-sm shadow-sm' }}">{{ $msg->content }}</div>
                    @if($msg->role === 'user' && $msg->page_url)
                    <p class="text-right mt-1">
                        <a href="{{ $msg->page_url }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1 text-[10px] font-mono text-orange-200 hover:text-white transition-colors break-all">
                            <svg class="w-3 h-3 flex-shrink-0 flex-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                            {{ $msg->page_url }}
                        </a>
                    </p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>

    @endforeach
    </div>

    @endif

</div>

{{-- Auto-deschide sesiunea dacă vine din widget (?open=SESSION_ID) --}}
<script>
(function () {
    const id = new URLSearchParams(window.location.search).get('open');
    if (!id) return;
    function tryOpen() {
        const el = document.getElementById('session-' + id);
        if (el && window.Alpine) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const data = Alpine.$data(el);
            if (data) data.open = true;
        } else {
            setTimeout(tryOpen, 150);
        }
    }
    setTimeout(tryOpen, 400);
})();
</script>

</x-filament-panels::page>
