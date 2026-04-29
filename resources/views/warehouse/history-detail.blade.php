@extends('warehouse.layout')
@section('title', 'Recepție — ' . $order->number)

@section('body')
<div class="wh-header">
    <a href="{{ route('warehouse.history') }}" class="back-btn">‹</a>
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <div style="width:48px"></div>
</div>

<div class="wh-content">
    {{-- Header info --}}
    <div class="wh-card" style="margin-bottom:16px; border-left:4px solid #16a34a">
        <div style="font-weight:700; font-size:17px; color:#b91c1c; margin-bottom:4px">{{ $order->number }}</div>
        <div style="font-weight:700; font-size:15px; color:#1e293b; margin-bottom:8px">{{ $order->supplier?->name ?? '—' }}</div>
        <div style="display:flex; flex-wrap:wrap; gap:12px; font-size:13px; color:#64748b">
            @if($order->received_at)
            <span>📅 {{ $order->received_at->format('d.m.Y H:i') }}</span>
            @endif
            @if($order->receivedBy)
            <span>👤 {{ $order->receivedBy->name }}</span>
            @endif
            <span>📦 {{ $order->items->count() }} produse</span>
        </div>
    </div>

    {{-- Badge read-only + WinMentor status --}}
    <div class="banner banner-info" style="margin-bottom:12px; font-size:13px">
        🔒 Vizualizare — recepție finalizată, nu poate fi modificată
    </div>
    @php
        $wmStatus = $order->winmentor_sync_status;
        [$wmColor, $wmIcon, $wmLabel] = match($wmStatus) {
            'synced'  => ['#16a34a', '✅', 'Sincronizat în WinMentor'],
            'failed'  => ['#dc2626', '❌', 'Eroare sincronizare WinMentor' . ($order->winmentor_sync_error ? ': ' . $order->winmentor_sync_error : '')],
            'pending' => ['#f59e0b', '⏳', 'În așteptare sincronizare WinMentor'],
            default   => ['#94a3b8', '—',  'Nesincronizat cu WinMentor'],
        };
    @endphp
    <div style="background:white; border-radius:12px; padding:12px 16px; margin-bottom:16px; border-left:4px solid {{ $wmColor }}; display:flex; align-items:center; gap:10px; font-size:13px; font-weight:600; color:{{ $wmColor }}">
        <span>{{ $wmIcon }}</span>
        <span>{{ $wmLabel }}</span>
        @if($wmStatus === 'synced' && $order->winmentor_synced_at)
            <span style="font-weight:400; color:#64748b; margin-left:auto">{{ $order->winmentor_synced_at->format('d.m.Y H:i') }}</span>
        @endif
    </div>

    {{-- Items --}}
    @foreach($order->items as $item)
    @php
        $ordered  = (float) $item->quantity;
        $received = (float) ($item->received_quantity ?? 0);
        $diff     = $received - $ordered;
        $borderColor = $diff === 0.0 ? '#16a34a' : ($diff < 0 ? '#dc2626' : '#f59e0b');
    @endphp
    <div style="background:white; border-radius:14px; padding:16px; margin-bottom:10px; box-shadow:0 1px 3px rgba(0,0,0,0.07); border-left:4px solid {{ $borderColor }}">
        <div style="font-size:15px; font-weight:600; color:#b91c1c; margin-bottom:4px">{{ $item->product_name }}</div>
        @if($item->sku)
        <div style="font-size:12px; color:#94a3b8; margin-bottom:8px">SKU: {{ $item->sku }}</div>
        @endif
        <div style="display:flex; align-items:center; gap:8px; font-size:14px; color:#475569; flex-wrap:wrap">
            <span>Comandat: <strong>{{ number_format($ordered, 0) }}</strong></span>
            <span style="color:#94a3b8">→</span>
            <span>Primit: <strong style="color:#1e293b; font-size:16px">{{ number_format($received, 0) }}</strong></span>
            @if($diff != 0)
                @php $sign = $diff > 0 ? '+' : ''; @endphp
                <span style="font-size:12px; font-weight:700; color:{{ $diff < 0 ? '#dc2626' : '#16a34a' }}">
                    {{ $sign }}{{ number_format($diff, 0) }}
                </span>
            @endif
        </div>
        @if($item->notes)
        <div style="font-size:12px; color:#64748b; margin-top:6px; font-style:italic">{{ $item->notes }}</div>
        @endif
    </div>
    @endforeach

    {{-- Observații --}}
    @if($order->received_notes)
    <div class="wh-card" style="margin-top:8px; border-left:4px solid #64748b">
        <div style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px">Observații</div>
        <div style="font-size:14px; color:#1e293b; white-space:pre-wrap">{{ $order->received_notes }}</div>
    </div>
    @endif

    <div style="height:32px"></div>
</div>
@endsection
