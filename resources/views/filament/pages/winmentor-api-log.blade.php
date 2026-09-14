<x-filament-panels::page>

    @php
        $dates = $this->getAvailableDates();
        $stats = $this->getStats();
        $logs  = $this->getLogs();
    @endphp

    {{-- Toolbar --}}
    <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.25rem;">

        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span style="font-size:0.875rem; font-weight:500; color:#374151;">Data:</span>
            <select wire:model.live="date"
                style="border:1px solid #d1d5db; border-radius:0.5rem; padding:0.25rem 0.75rem; font-size:0.875rem; background:#fff; cursor:pointer;">
                @foreach($dates as $d)
                    <option value="{{ $d }}">{{ \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex; align-items:center; gap:0.4rem;">
            <span style="font-size:0.875rem; font-weight:500; color:#374151;">Metodă:</span>
            @foreach(['all' => 'Toate', 'GET' => 'GET', 'POST' => 'POST'] as $val => $label)
                <button wire:click="$set('method', '{{ $val }}')"
                    style="padding:0.25rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:600; border:none; cursor:pointer;
                        {{ $method === $val ? 'background:#1e40af; color:#fff;' : 'background:#f3f4f6; color:#374151;' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div style="display:flex; align-items:center; gap:0.4rem;">
            <span style="font-size:0.875rem; font-weight:500; color:#374151;">Status:</span>
            @foreach(['all' => 'Toate', 'ok' => 'OK', 'error' => 'Erori'] as $val => $label)
                @php $activeBg = $val === 'error' ? '#dc2626' : ($val === 'ok' ? '#059669' : '#1e40af'); @endphp
                <button wire:click="$set('status', '{{ $val }}')"
                    style="padding:0.25rem 0.7rem; border-radius:9999px; font-size:0.8rem; font-weight:600; border:none; cursor:pointer;
                        {{ $status === $val ? "background:{$activeBg}; color:#fff;" : 'background:#f3f4f6; color:#374151;' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <input type="text" wire:model.live.debounce.400ms="search" placeholder="caută endpoint…"
            style="border:1px solid #d1d5db; border-radius:0.5rem; padding:0.25rem 0.75rem; font-size:0.875rem; background:#fff; min-width:180px;">

        <button wire:click="$refresh"
            style="margin-left:auto; padding:0.25rem 0.75rem; border-radius:0.5rem; font-size:0.875rem; font-weight:500;
                border:1px solid #d1d5db; background:#fff; cursor:pointer;">
            ↺ Refresh
        </button>
    </div>

    {{-- Stat cards --}}
    <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:0.75rem; margin-bottom:1.25rem;">
        @foreach([
            ['Total apeluri', $stats['total'], '#1e40af'],
            ['GET', $stats['gets'], '#0369a1'],
            ['POST / PUT', $stats['posts'], '#7c3aed'],
            ['OK', $stats['ok'], '#059669'],
            ['Erori', $stats['errors'], '#dc2626'],
            ['Durată medie', $stats['avg_ms'].' ms', '#b45309'],
        ] as [$label, $val, $color])
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.08); padding:0.8rem 1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.68rem; color:#6b7280; margin:0 0 0.2rem; text-transform:uppercase; letter-spacing:0.05em;">{{ $label }}</p>
            <p style="font-size:1.4rem; font-weight:700; color:{{ $color }}; margin:0;">{{ $val }}</p>
        </div>
        @endforeach
    </div>

    {{-- Log entries --}}
    @if($logs->isEmpty())
        <div style="background:#fff; border-radius:0.75rem; padding:2rem; text-align:center; color:#6b7280; border:1px solid #e5e7eb;">
            Niciun apel MentorAPI pentru filtrele selectate.
        </div>
    @else
        @if($logs->count() >= $this->limit)
            <p style="font-size:0.75rem; color:#9ca3af; margin:0 0 0.5rem;">Se afișează cele mai recente {{ $this->limit }} apeluri. Rafinează cu filtrele de mai sus.</p>
        @endif

        <div style="display:flex; flex-direction:column; gap:0.4rem;">
            @foreach($logs as $log)
                @php
                    $isError = ! $log->success;
                    $isPost  = in_array($log->method, ['POST','PUT']);
                    $isResp  = false;

                    $borderColor = $isError ? '#fca5a5' : ($isPost ? '#c4b5fd' : '#bfdbfe');
                    $bgColor     = $isError ? '#fff7f7' : ($isPost ? '#faf5ff' : '#f0f9ff');
                    $badgeBg     = $isError ? '#dc2626' : ($isPost ? '#7c3aed' : '#0369a1');

                    $statusColor = $log->status_code >= 200 && $log->status_code < 300 ? '#059669'
                                 : ($log->status_code >= 400 ? '#dc2626' : '#b45309');

                    $reqPretty = json_decode($log->request_body);
                    $reqPretty = $reqPretty !== null ? json_encode($reqPretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($log->request_body ?: '—');
                    $resPretty = json_decode($log->response_body);
                    $resPretty = $resPretty !== null ? json_encode($resPretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ($log->response_body ?: '—');
                @endphp

                <div x-data="{ open:false }"
                     style="background:{{ $bgColor }}; border:1px solid {{ $borderColor }}; border-radius:0.5rem; padding:0.6rem 0.875rem; font-family:monospace; font-size:0.8rem;">
                    <div style="display:flex; align-items:center; gap:0.7rem;">
                        <span style="color:#6b7280; white-space:nowrap; flex-shrink:0;">{{ $log->created_at->format('H:i:s') }}</span>
                        <span style="background:{{ $badgeBg }}; color:#fff; border-radius:0.25rem; padding:0.05rem 0.45rem; font-size:0.7rem; font-weight:700; white-space:nowrap; flex-shrink:0;">{{ $log->method }}</span>
                        <span style="color:#111827; word-break:break-all; flex:1;">{{ $log->endpoint }}</span>
                        @if($log->context)
                            <span style="background:#e5e7eb; color:#374151; border-radius:0.25rem; padding:0.05rem 0.4rem; font-size:0.68rem; white-space:nowrap;">{{ $log->context }}</span>
                        @endif
                        <span style="color:{{ $statusColor }}; font-weight:700; white-space:nowrap;">{{ $log->status_code ?: '—' }}</span>
                        <span style="color:#6b7280; white-space:nowrap;">{{ $log->duration_ms }} ms</span>
                        <button @click="open = !open"
                            style="background:#fff; border:1px solid #d1d5db; border-radius:0.35rem; padding:0.1rem 0.5rem; font-size:0.7rem; font-weight:600; color:#374151; cursor:pointer; white-space:nowrap;">
                            <span x-show="!open">▸ raw</span><span x-show="open" x-cloak>▾ raw</span>
                        </button>
                    </div>

                    <div x-show="open" x-cloak style="margin-top:0.5rem; display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                        <div>
                            <p style="margin:0 0 0.2rem; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#6b7280;">Request</p>
                            <pre style="margin:0; background:#0f172a; color:#e2e8f0; border-radius:0.4rem; padding:0.6rem; font-size:0.72rem; line-height:1.45; max-height:280px; overflow:auto; white-space:pre-wrap; word-break:break-word;">{{ $reqPretty }}</pre>
                        </div>
                        <div>
                            <p style="margin:0 0 0.2rem; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#6b7280;">Response</p>
                            <pre style="margin:0; background:{{ $isError ? '#450a0a' : '#0f172a' }}; color:{{ $isError ? '#fecaca' : '#e2e8f0' }}; border-radius:0.4rem; padding:0.6rem; font-size:0.72rem; line-height:1.45; max-height:280px; overflow:auto; white-space:pre-wrap; word-break:break-word;">{{ $resPretty }}</pre>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</x-filament-panels::page>
