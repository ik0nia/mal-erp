<x-filament-panels::page>
    @php $stats = $this->getStats(); @endphp

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:8px">
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:14px 16px">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">Total PO cu abateri</div>
            <div style="font-size:26px;font-weight:800;color:{{ $stats['total'] > 0 ? '#b45309' : '#059669' }}">{{ $stats['total'] }}</div>
        </div>
        @foreach ($stats['byType'] as $type => $count)
            <div style="background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:14px 16px">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600">{{ $type }}</div>
                <div style="font-size:26px;font-weight:800;color:{{ $count > 0 ? '#dc2626' : '#059669' }}">{{ $count }}</div>
            </div>
        @endforeach
    </div>

    @if (! empty($stats['byBuyer']))
        <div style="background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:12px 16px;margin-bottom:8px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b1;font-weight:600;margin-bottom:8px">Abateri per cumpărător</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach ($stats['byBuyer'] as $name => $count)
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#f9fafb;border:1px solid #eceff3;border-radius:9999px;padding:4px 12px;font-size:13px">
                        <strong>{{ $name }}</strong>
                        <span style="background:{{ $count >= 5 ? '#fee2e2' : '#eef2f7' }};color:{{ $count >= 5 ? '#b91c1c' : '#3e4c59' }};border-radius:9999px;padding:0 8px;font-weight:700">{{ $count }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
