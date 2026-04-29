<x-filament-panels::page>

    @php
        $dates = $this->getAvailableDates();
        $stats = $this->getStats();
        $logs  = $this->getLogs();
    @endphp

    {{-- Toolbar --}}
    <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">

        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span style="font-size:0.875rem; font-weight:500; color:#374151;">Data:</span>
            <select wire:model.live="date"
                style="border:1px solid #d1d5db; border-radius:0.5rem; padding:0.25rem 0.75rem; font-size:0.875rem; background:#fff; cursor:pointer;">
                @foreach($dates as $d)
                    <option value="{{ $d }}">{{ $d }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex; align-items:center; gap:0.5rem;">
            <span style="font-size:0.875rem; font-weight:500; color:#374151;">Nivel:</span>
            @foreach(['all' => 'Toate', 'info' => 'Info', 'error' => 'Erori'] as $val => $label)
                <button wire:click="$set('level', '{{ $val }}')"
                    style="padding:0.25rem 0.75rem; border-radius:9999px; font-size:0.875rem; font-weight:500; border:none; cursor:pointer;
                        {{ $level === $val ? 'background:#1e40af; color:#fff;' : 'background:#f3f4f6; color:#374151;' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <button wire:click="$refresh"
            style="margin-left:auto; padding:0.25rem 0.75rem; border-radius:0.5rem; font-size:0.875rem; font-weight:500;
                border:1px solid #d1d5db; background:#fff; cursor:pointer; display:flex; align-items:center; gap:0.4rem;">
            ↺ Refresh
        </button>
    </div>

    {{-- Stat cards --}}
    <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:0.75rem; margin-bottom:1.5rem;">
        @foreach([
            ['Total intrări', $stats['total'], '#1e40af'],
            ['GET', $stats['gets'], '#0369a1'],
            ['POST', $stats['posts'], '#7c3aed'],
            ['Răspunsuri', $stats['resp'], '#065f46'],
            ['Erori', $stats['errors'], '#dc2626'],
        ] as [$label, $val, $color])
        <div style="background:#fff; border-radius:0.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.08); padding:0.875rem 1rem; border:1px solid #e5e7eb;">
            <p style="font-size:0.7rem; color:#6b7280; margin:0 0 0.2rem; text-transform:uppercase; letter-spacing:0.05em;">{{ $label }}</p>
            <p style="font-size:1.5rem; font-weight:700; color:{{ $color }}; margin:0;">{{ $val }}</p>
        </div>
        @endforeach
    </div>

    {{-- Log entries --}}
    @if(empty($logs))
        <div style="background:#fff; border-radius:0.75rem; padding:2rem; text-align:center; color:#6b7280; border:1px solid #e5e7eb;">
            Nu există înregistrări WinMentor Bridge pentru data selectată.
        </div>
    @else
        <div style="display:flex; flex-direction:column; gap:0.4rem;">
            @foreach($logs as $entry)
                @php
                    $isError = $entry['level'] === 'error';
                    $isPost  = str_contains($entry['message'], '] POST ');
                    $isGet   = str_contains($entry['message'], '] GET ');
                    $isResp  = str_contains($entry['message'], '] Response ');

                    $borderColor = $isError ? '#fca5a5' : ($isPost ? '#c4b5fd' : ($isResp ? '#6ee7b7' : '#bfdbfe'));
                    $bgColor     = $isError ? '#fff7f7' : ($isPost ? '#faf5ff' : ($isResp ? '#f0fdf4' : '#f0f9ff'));
                    $badgeBg     = $isError ? '#dc2626' : ($isPost ? '#7c3aed' : ($isResp ? '#059669' : '#0369a1'));
                    $badge       = $isError ? 'ERROR' : ($isPost ? 'POST' : ($isResp ? 'RESP' : 'GET'));

                    // Extrage URL din mesaj
                    $msg = $entry['message'];
                    $clean = preg_replace('/^\[WinMentor Bridge\] /', '', $msg);

                    // Parsează contextul JSON
                    $ctxJson = trim($entry['context']);
                    $ctx = null;
                    if ($ctxJson && str_starts_with($ctxJson, '{')) {
                        $ctx = json_decode($ctxJson, true);
                    }
                @endphp

                <div style="background:{{ $bgColor }}; border:1px solid {{ $borderColor }}; border-radius:0.5rem; padding:0.6rem 0.875rem; font-family:monospace; font-size:0.8rem;">
                    <div style="display:flex; align-items:flex-start; gap:0.75rem;">
                        <span style="color:#6b7280; white-space:nowrap; flex-shrink:0;">{{ $entry['ts'] }}</span>
                        <span style="background:{{ $badgeBg }}; color:#fff; border-radius:0.25rem; padding:0.05rem 0.4rem; font-size:0.7rem; font-weight:700; white-space:nowrap; flex-shrink:0;">{{ $badge }}</span>
                        <span style="color:#111827; word-break:break-all;">{{ $clean }}</span>
                    </div>
                    @if($ctx)
                        <div style="margin-top:0.35rem; padding:0.35rem 0.6rem; background:rgba(0,0,0,0.04); border-radius:0.35rem; font-size:0.75rem; color:#374151; word-break:break-all;">
                            @foreach($ctx as $k => $v)
                                <span style="color:#6b7280;">{{ $k }}:</span>
                                <span>{{ is_array($v) ? implode(', ', $v) : $v }}</span>
                                @if(!$loop->last) &nbsp;·&nbsp; @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</x-filament-panels::page>
