@foreach ($rows as $r)
    @php [$cmd, $freq, $tags, $desc, $links] = $r; @endphp
    <table class="cron">
        <tr class="crow-head">
            <td>
                <span class="freq">{{ $freq }}</span>
                <span class="cmd">{{ $cmd }}</span>
            </td>
        </tr>
        <tr>
            <td>
                @foreach (explode(' ', trim($tags)) as $t)
                    @php
                        $label = ['t-scrie' => 'SCRIE DB', 't-push' => 'PUSH SITE', 't-read' => 'READ-ONLY', 't-mail' => 'ALERTĂ'][$t] ?? '';
                    @endphp
                    @if ($label)<span class="tag {{ $t }}">{{ $label }}</span>@endif
                @endforeach
                <span class="desc">{!! $desc !!}</span>
                <div class="links"><span class="arrow">⤷</span> {!! $links !!}</div>
            </td>
        </tr>
    </table>
@endforeach
