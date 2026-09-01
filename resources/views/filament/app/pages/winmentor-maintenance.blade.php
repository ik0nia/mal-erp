<x-filament-panels::page>
    <div wire:poll.10s="refreshHealth" class="contents">
    @if (! $reachable)
        <x-filament::section>
            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-8 w-8 text-danger-500" />
                <div>
                    <p class="text-lg font-semibold text-danger-600">MentorAPI nu răspunde</p>
                    <p class="text-sm text-gray-500">Serverul de pe PC-ul WinMentor nu poate fi contactat. Verifică dacă serviciul MentorAPI rulează.</p>
                </div>
            </div>
        </x-filament::section>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm text-gray-500 mb-2">Conexiune COM WinMentor</p>
                    @if ($this->isComConnected())
                        <x-filament::badge color="success" size="lg">CONECTAT</x-filament::badge>
                    @elseif ($connecting)
                        <x-filament::badge color="warning" size="lg" class="animate-pulse">RECONECTARE ÎN CURS…</x-filament::badge>
                        <p class="text-xs text-gray-400 mt-2">Durează ~1 minut. Pagina se actualizează singură.</p>
                    @else
                        <x-filament::badge color="danger" size="lg">DECONECTAT</x-filament::badge>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm text-gray-500 mb-2">Stare server</p>
                    @php $status = $health['status'] ?? '—'; @endphp
                    <x-filament::badge :color="$status === 'running' ? 'success' : ($status === 'maintenance' ? 'warning' : 'gray')" size="lg">
                        {{ $status === 'running' ? 'FUNCȚIONAL' : ($status === 'maintenance' ? 'MENTENANȚĂ' : strtoupper($status)) }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <p class="text-sm text-gray-500 mb-2">Versiune MentorAPI</p>
                    <p class="text-lg font-semibold">v{{ $health['version'] ?? '—' }}</p>
                    <p class="text-xs text-gray-400">pornit de {{ preg_replace('/\.\d+s/', 's', $health['uptime'] ?? '—') }}</p>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Cum funcționează</x-slot>
            <div class="text-sm text-gray-600 dark:text-gray-300 space-y-2">
                <p><strong>Deconectează COM</strong> — oprește legătura dintre ERP și WinMentor. Folosește-o când Mentor cere toți utilizatorii deconectați (ex. închidere de lună, verificări de date). Cât timp e deconectat, sincronizările automate (vânzări, recepții, stocuri, comenzi) sunt în pauză — nu se pierd, se reiau de unde au rămas.</p>
                <p><strong>Reconectează COM</strong> — reface legătura și reia sincronizările imediat. <strong>Nu uita să reconectezi după ce termini operațiunea în Mentor!</strong></p>
                <p class="text-gray-400">WinMentor de pe calculatoare funcționează normal în ambele cazuri — doar legătura cu ERP-ul e afectată.</p>
            </div>
        </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
