<x-filament-widgets::widget>
    <x-filament::section class="!p-0 overflow-hidden">
        <div wire:poll.10s="refreshHealth">

            @php
                $ok       = $reachable && $this->isComConnected();
                $offline  = ! $reachable;

                // Paletă pe stări (hex — inline, deci sigur randate)
                if ($offline)        { $c = '#6b7280'; $cbg = '#f3f4f6'; $lbl = 'MentorAPI indisponibil'; }
                elseif ($ok)         { $c = '#16a34a'; $cbg = '#dcfce7'; $lbl = 'Conectat'; }
                elseif ($connecting) { $c = '#d97706'; $cbg = '#fef3c7'; $lbl = 'Reconectare în curs…'; }
                else                 { $c = '#dc2626'; $cbg = '#fee2e2'; $lbl = 'Deconectat'; }

                $server  = $health['status'] ?? null;
                $upd     = $this->updateInfo();
                $history = $this->versionHistory();
                $compLbl = \App\Models\WinmentorVersionHistory::COMPONENTS;

                $tile = 'border:1px solid #e5e7eb; border-radius:0.6rem; padding:0.65rem 0.5rem; text-align:center; background:#fff;';
                $tlab = 'font-size:0.62rem; letter-spacing:0.06em; text-transform:uppercase; color:#9ca3af; margin:0 0 0.35rem;';
                $tval = 'font-size:0.9rem; font-weight:700; color:#111827; margin:0; line-height:1.2;';
                $tsub = 'font-size:0.65rem; color:#9ca3af; margin:0.1rem 0 0;';
            @endphp

            <div style="display:flex; align-items:stretch;">
                {{-- Accent lateral --}}
                <div style="width:5px; background:{{ $c }}; flex-shrink:0;"></div>

                <div style="flex:1; padding:1rem 1.15rem;">

                    {{-- Header: identitate + status + butoane --}}
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:0.9rem;">
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <div style="display:flex; height:2.6rem; width:2.6rem; align-items:center; justify-content:center; border-radius:0.7rem; background:{{ $cbg }};">
                                <x-filament::icon icon="heroicon-o-power" style="height:1.35rem; width:1.35rem; color:{{ $c }};" />
                            </div>
                            <div>
                                <p style="font-size:0.95rem; font-weight:700; color:#111827; margin:0;">Mentenanță COM WinMentor</p>
                                <div style="display:flex; align-items:center; gap:0.4rem; margin-top:0.15rem;">
                                    <span style="display:inline-block; height:0.5rem; width:0.5rem; border-radius:9999px; background:{{ $c }};" @if($ok || $connecting) class="animate-ping-slow" @endif></span>
                                    <span style="font-size:0.78rem; font-weight:600; color:{{ $c }};">{{ $lbl }}</span>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            {{ $this->refreshAction }}

                            @if ($reachable && $this->isComConnected())
                                {{ $this->disconnectAction }}
                            @elseif ($reachable && ! $this->isComConnected() && ! $connecting)
                                {{ $this->connectAction }}
                            @endif
                        </div>
                    </div>

                    {{-- Coloane cu detalii --}}
                    @if ($reachable)
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:0.6rem;">
                            <div style="{{ $tile }}">
                                <p style="{{ $tlab }}">Conexiune COM</p>
                                @if ($this->isComConnected())
                                    <x-filament::badge color="success">CONECTAT</x-filament::badge>
                                @elseif ($connecting)
                                    <x-filament::badge color="warning">RECONECTARE…</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger">DECONECTAT</x-filament::badge>
                                @endif
                            </div>

                            <div style="{{ $tile }}">
                                <p style="{{ $tlab }}">Stare server</p>
                                <x-filament::badge :color="$server === 'running' ? 'success' : ($server === 'maintenance' ? 'warning' : 'gray')">
                                    {{ $server === 'running' ? 'FUNCȚIONAL' : ($server === 'maintenance' ? 'MENTENANȚĂ' : strtoupper($server ?? '—')) }}
                                </x-filament::badge>
                            </div>

                            <div style="{{ $tile }}">
                                <p style="{{ $tlab }}">Uptime</p>
                                <p style="{{ $tval }}">{{ $this->uptimeHuman() }}</p>
                                <p style="{{ $tsub }}">fără întrerupere</p>
                            </div>

                            <div style="{{ $tile }}">
                                <p style="{{ $tlab }}">MentorAPI</p>
                                <p style="{{ $tval }}">v{{ $health['version'] ?? '—' }}</p>
                                <p style="{{ $tsub }}">bridge Go</p>
                            </div>

                            <div style="{{ $tile }}">
                                <p style="{{ $tlab }}">WinMentor instalat</p>
                                <p style="{{ $tval }}">{{ $versiuni['mentor'] ?? '—' }}</p>
                                <p style="{{ $tsub }}">Server: {{ $versiuni['server'] ?? '—' }}</p>
                            </div>

                            <div style="{{ $tile }}@if($upd['available']) border-color:#fdba74; background:#fff7ed;@endif">
                                <p style="{{ $tlab }}">Actualizare WinMentor</p>
                                @if ($upd['latest'])
                                    @if ($upd['available'])
                                        <x-filament::badge color="warning" icon="heroicon-m-arrow-up-circle">{{ $upd['latest'] }}</x-filament::badge>
                                        <p style="{{ $tsub }}">disponibilă{{ $upd['date'] ? ' din '.\Illuminate\Support\Carbon::parse($upd['date'])->format('d.m.Y') : '' }}</p>
                                    @else
                                        <x-filament::badge color="success" icon="heroicon-m-check-circle">LA ZI</x-filament::badge>
                                        <p style="{{ $tsub }}">{{ $upd['latest'] }} e ultima</p>
                                    @endif
                                @else
                                    <p style="{{ $tval }}">—</p>
                                    <p style="{{ $tsub }}">necunoscut</p>
                                @endif
                            </div>
                        </div>
                    @else
                        <div style="border:1px solid #fecaca; background:#fef2f2; border-radius:0.6rem; padding:0.8rem 1rem; font-size:0.82rem; color:#b91c1c;">
                            Serverul MentorAPI de pe PC-ul WinMentor nu răspunde. Verifică dacă serviciul rulează.
                        </div>
                    @endif

                    {{-- Istoric actualizări aplicate pe server --}}
                    <div x-data="{ open: false }" style="margin-top:0.85rem; border-top:1px solid #f3f4f6; padding-top:0.6rem;">
                        <button @click="open = !open" type="button"
                            style="display:flex; align-items:center; gap:0.4rem; background:none; border:none; cursor:pointer; padding:0; font-size:0.72rem; font-weight:600; color:#6b7280;">
                            <x-filament::icon icon="heroicon-m-clock" style="height:0.9rem; width:0.9rem;" />
                            Istoric actualizări pe server
                            @if($history->isNotEmpty())
                                <span style="background:#eef2f7; color:#3e4c59; border-radius:9999px; padding:0.05rem 0.45rem; font-size:0.65rem;">{{ $history->count() }}</span>
                            @endif
                            <span x-show="!open">▸</span><span x-show="open" x-cloak>▾</span>
                        </button>

                        <div x-show="open" x-cloak style="margin-top:0.5rem;">
                            @if($history->isEmpty())
                                <p style="font-size:0.72rem; color:#9ca3af; margin:0;">Urmărire activă — nicio schimbare de versiune înregistrată încă. Când se aplică un update pe server, apare aici automat (verificare orară).</p>
                            @else
                                <div style="display:flex; flex-direction:column; gap:0.3rem;">
                                    @foreach($history as $h)
                                        <div style="display:flex; align-items:center; gap:0.6rem; font-size:0.74rem; padding:0.35rem 0.55rem; background:#f9fafb; border-radius:0.4rem;">
                                            <span style="color:#9ca3af; white-space:nowrap; min-width:5.5rem;">{{ $h->detected_at->format('d.m.Y H:i') }}</span>
                                            <span style="font-weight:600; color:#374151; min-width:5.5rem;">{{ $compLbl[$h->component] ?? $h->component }}</span>
                                            <span style="color:#9ca3af; font-family:monospace;">{{ $h->previous_version }}</span>
                                            <x-filament::icon icon="heroicon-m-arrow-right" style="height:0.8rem; width:0.8rem; color:#16a34a;" />
                                            <span style="color:#111827; font-weight:700; font-family:monospace;">{{ $h->version }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <x-filament-actions::modals />
    </x-filament::section>

    <style>
        @keyframes ping-slow { 75%, 100% { transform: scale(2); opacity: 0; } }
        .animate-ping-slow { position: relative; }
    </style>
</x-filament-widgets::widget>
