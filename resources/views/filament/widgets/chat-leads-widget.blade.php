@php
    $leadsData = $this->getLeadsData();
    $modalData = $this->getModalData();
@endphp

<div>
@if(count($leadsData) > 0)
<x-filament-widgets::widget>
    <div class="rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900 overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-white/5">
            <x-heroicon-o-chat-bubble-left-ellipsis class="w-4 h-4 text-warning-500"/>
            <span class="font-semibold text-gray-900 dark:text-white text-sm">Lead-uri chat necontactate</span>
            <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full
                         bg-warning-100 dark:bg-warning-900/40
                         text-warning-700 dark:text-warning-400 text-xs font-bold">
                {{ count($leadsData) }}
            </span>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach($leadsData as $lead)
            <div class="flex items-center gap-4 px-6 py-3 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if($lead['email'])
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                            <x-heroicon-o-envelope class="w-3 h-3 shrink-0"/>{{ $lead['email'] }}
                        </span>
                        @endif
                        @if($lead['phone'])
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                            <x-heroicon-o-phone class="w-3 h-3 shrink-0"/>{{ $lead['phone'] }}
                        </span>
                        @endif
                        @if($lead['wants_specialist'])
                        <x-filament::badge color="warning" size="sm">Vrea specialist</x-filament::badge>
                        @endif
                    </div>
                    @if($lead['summary'])
                    <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $lead['summary'] }}</p>
                    @endif
                    <p class="text-xs text-gray-400 mt-0.5">{{ $lead['ago'] }}</p>
                </div>

                <x-filament::button
                    wire:click="openModal('{{ $lead['session_id'] }}')"
                    size="sm" color="gray" icon="heroicon-o-eye">
                    Vezi conversația
                </x-filament::button>
            </div>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
@endif

@if($openSession && $modalData)
{{-- Blochează scroll pagină cât timp modalul e deschis --}}
<style>body{overflow:hidden!important}</style>

<div x-data x-on:keydown.escape.window="$wire.closeModal()"
     class="fixed inset-0 z-[200] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" wire:click="closeModal"></div>

    {{-- Înălțime fixă = flex funcționează corect, mesajele scrollează --}}
    <div class="relative z-10 w-full max-w-2xl flex flex-col
                bg-white dark:bg-gray-900 rounded-xl shadow-xl
                border border-gray-200 dark:border-white/10 overflow-hidden"
         style="height:680px;max-height:85vh">

        {{-- Header --}}
        <div class="shrink-0 flex items-start gap-3 px-6 py-4 border-b border-gray-100 dark:border-white/10">
            <div class="flex-1 min-w-0">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Conversație client</h3>
                <div class="flex flex-wrap items-center gap-2 mt-1">
                    @if($modalData['email'])
                    <a href="mailto:{{ $modalData['email'] }}"
                       class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:underline">
                        <x-heroicon-o-envelope class="w-3 h-3 shrink-0"/>{{ $modalData['email'] }}
                    </a>
                    @endif
                    @if($modalData['phone'])
                    <a href="tel:{{ $modalData['phone'] }}"
                       class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:underline">
                        <x-heroicon-o-phone class="w-3 h-3 shrink-0"/>{{ $modalData['phone'] }}
                    </a>
                    @endif
                    @if($modalData['wants_specialist'])
                    <x-filament::badge color="warning" size="sm">Vrea specialist</x-filament::badge>
                    @endif
                </div>
                @if($modalData['summary'])
                <p class="text-xs text-gray-400 mt-1 italic">{{ $modalData['summary'] }}</p>
                @endif
            </div>
            <button type="button" wire:click="closeModal"
                    class="shrink-0 p-1.5 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10 transition-colors">
                <x-heroicon-o-x-mark class="w-5 h-5"/>
            </button>
        </div>

        {{-- Mesaje: flex-1 + min-h-0 este cheia pentru scroll corect --}}
        <div class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain px-6 py-4 space-y-3 bg-gray-50 dark:bg-gray-800/30">
            @foreach($modalData['messages'] as $msg)
            <div class="{{ $msg['role'] === 'user' ? 'flex justify-end' : 'flex justify-start' }}">
                <div class="max-w-[75%]">
                    <p class="text-xs text-gray-400 mb-1 {{ $msg['role'] === 'user' ? 'text-right' : '' }}">
                        {{ $msg['role'] === 'user' ? 'Client' : 'Alex' }} · {{ $msg['time'] }}
                    </p>
                    <div class="rounded-2xl px-4 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words"
                         style="{{ $msg['role'] === 'user'
                             ? 'background:#f97316;color:#fff;border-bottom-right-radius:4px'
                             : 'background:#fff;color:#1f2937;border:1px solid #e5e7eb;border-bottom-left-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,.05)' }}">
                        {{ $msg['content'] }}
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Footer --}}
        <div class="shrink-0 flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 dark:border-white/10">
            <span class="text-xs text-gray-400">{{ $modalData['ago'] }}</span>
            <div class="flex gap-2">
                <x-filament::button wire:click="closeModal" color="gray">
                    Închide
                </x-filament::button>
                <x-filament::button
                    wire:click="markAsContacted('{{ $modalData['session_id'] }}')"
                    color="success" icon="heroicon-o-check">
                    Marchează contactat
                </x-filament::button>
            </div>
        </div>
    </div>
</div>
@endif
</div>
