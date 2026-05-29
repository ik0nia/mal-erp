@extends('warehouse.layout')
@section('title', 'Recepție — ' . $order->number)

@section('body')
<div class="wh-header">
    <a href="{{ route('warehouse.history') }}" class="back-btn">‹</a>
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <div style="width:48px"></div>
</div>

<div class="wh-content">
    @php
        $isPartial = $order->status === \App\Models\PurchaseOrder::STATUS_PARTIALLY_RECEIVED;
        $receptions = $order->receptions->sortBy('reception_number');
        $hasReceptions = $receptions->isNotEmpty();
        $borderColor = $isPartial ? '#f59e0b' : '#16a34a';
    @endphp

    {{-- Header info --}}
    <div class="wh-card" style="margin-bottom:16px; border-left:4px solid {{ $borderColor }}">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px">
            <div style="font-weight:700; font-size:17px; color:#b91c1c">{{ $order->number }}</div>
            @if($isPartial)
                <span style="background:#f59e0b; color:white; font-size:11px; font-weight:700; padding:2px 8px; border-radius:8px">PARȚIAL</span>
            @endif
        </div>
        <div style="font-weight:700; font-size:15px; color:#1e293b; margin-bottom:8px">{{ $order->supplier?->name ?? '—' }}</div>
        <div style="display:flex; flex-wrap:wrap; gap:12px; font-size:13px; color:#64748b">
            @if($order->received_at)
            <span>{{ $order->received_at->format('d.m.Y H:i') }}</span>
            @endif
            @if($order->receivedBy)
            <span>{{ $order->receivedBy->name }}</span>
            @endif
            <span>{{ $order->items->count() }} produse</span>
            @if($hasReceptions)
            <span>{{ $receptions->count() }} {{ $receptions->count() === 1 ? 'recepție' : 'recepții' }}</span>
            @endif
        </div>
    </div>

    @if(!$isPartial)
    <div class="banner banner-info" style="margin-bottom:12px; font-size:13px">
        Vizualizare — recepție finalizată
    </div>
    @else
    <div class="banner banner-warning" style="margin-bottom:12px; font-size:13px">
        Recepție parțială — comanda are încă produse nerecepționate
    </div>
    @endif

    {{-- Istoric recepții per livrare --}}
    @if($hasReceptions)
    <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px">
        Recepții ({{ $receptions->count() }})
    </div>
    @foreach($receptions as $reception)
    @php
        $wmStatus = $reception->winmentor_sync_status;
        [$wmColor, $wmIcon] = match($wmStatus) {
            'synced'  => ['#16a34a', '✅'],
            'failed'  => ['#dc2626', '❌'],
            'pending' => ['#f59e0b', '⏳'],
            default   => ['#94a3b8', '—'],
        };
        $rBorder = $reception->is_final ? '#16a34a' : '#2563eb';
    @endphp
    <div style="background:white; border-radius:14px; padding:14px 16px; margin-bottom:10px; box-shadow:0 1px 3px rgba(0,0,0,0.07); border-left:4px solid {{ $rBorder }}">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; flex-wrap:wrap">
            <strong style="font-size:14px; color:#1e293b">Recepție #{{ $reception->reception_number }}</strong>
            @if($reception->is_final)
                <span style="background:#16a34a; color:white; font-size:10px; font-weight:700; padding:1px 6px; border-radius:6px">FINALĂ</span>
            @else
                <span style="background:#2563eb; color:white; font-size:10px; font-weight:700; padding:1px 6px; border-radius:6px">PARȚIALĂ</span>
            @endif
            <span style="font-size:12px; color:#64748b">{{ $reception->received_at->format('d.m.Y H:i') }}</span>
            @if($reception->receivedByUser)
            <span style="font-size:12px; color:#64748b">{{ $reception->receivedByUser->name }}</span>
            @endif
            <span title="WinMentor: {{ $wmStatus }}">{{ $wmIcon }}</span>
        </div>

        @foreach($reception->items as $ri)
        @php
            $poItem = $order->items->firstWhere('id', $ri->order_item_id);
        @endphp
        @if($poItem)
        <div style="display:flex; align-items:center; gap:8px; font-size:13px; color:#475569; padding:3px 0; flex-wrap:wrap">
            <span style="font-weight:600; color:#1e293b; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">{{ $poItem->product_name }}</span>
            <span style="font-weight:700; color:#15803d; white-space:nowrap">{{ number_format((float) $ri->received_quantity, 0) }} buc</span>
            @if($ri->invoice_position)
            <span style="font-size:11px; background:#f1f5f9; padding:1px 5px; border-radius:4px; color:#475569">poz. {{ $ri->invoice_position }}</span>
            @endif
        </div>
        @endif
        @endforeach

        @if($reception->received_notes)
        <div style="font-size:12px; color:#64748b; margin-top:6px; font-style:italic; border-top:1px solid #f1f5f9; padding-top:6px">{{ $reception->received_notes }}</div>
        @endif
    </div>
    @endforeach

    <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin:16px 0 8px">
        Sumar total per produs
    </div>
    @endif

    {{-- Items total --}}
    @foreach($order->items as $item)
    @php
        $ordered  = (float) $item->quantity;
        $received = (float) ($item->received_quantity ?? 0);
        $diff     = $received - $ordered;
        $itemBorder = $diff === 0.0 ? '#16a34a' : ($diff < 0 ? '#dc2626' : '#f59e0b');
    @endphp
    <div style="background:white; border-radius:14px; padding:16px; margin-bottom:10px; box-shadow:0 1px 3px rgba(0,0,0,0.07); border-left:4px solid {{ $itemBorder }}">
        <div style="font-size:15px; font-weight:600; color:#b91c1c; margin-bottom:4px">{{ $item->product_name }}</div>
        @if($item->sku)
        <div style="font-size:12px; color:#94a3b8; margin-bottom:8px">SKU: {{ $item->sku }}</div>
        @endif
        <div style="display:flex; align-items:center; gap:8px; font-size:14px; color:#475569; flex-wrap:wrap">
            <span>Comandat: <strong>{{ number_format($ordered, 0) }}</strong></span>
            <span style="color:#94a3b8">→</span>
            <span>Primit total: <strong style="color:#1e293b; font-size:16px">{{ number_format($received, 0) }}</strong></span>
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
