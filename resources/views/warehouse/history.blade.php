@extends('warehouse.layout')
@section('title', 'Recepții trecute')

@section('body')
<div class="wh-header">
    <a href="{{ route('warehouse.orders') }}" class="back-btn">‹</a>
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <div style="width:48px"></div>
</div>

<div class="wh-content">
    <div style="font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:16px">
        Recepții înregistrate
    </div>

    @if($orders->isEmpty())
        <div class="empty">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <h2>Nu există recepții</h2>
            <p>Nicio recepție înregistrată încă.</p>
        </div>
    @else
        @foreach($orders as $order)
        @php
            $isPartial = $order->status === \App\Models\PurchaseOrder::STATUS_PARTIALLY_RECEIVED;
            $receptionCount = $order->receptions->count();
            $borderColor = $isPartial ? '#f59e0b' : '#16a34a';
            // WinMentor status: per-reception dacă are recepții, altfel per PO (legacy)
            if ($receptionCount > 0) {
                $lastReception = $order->receptions->sortByDesc('reception_number')->first();
                $wmStatus = $lastReception->winmentor_sync_status ?? 'none';
            } else {
                $wmStatus = $order->winmentor_sync_status;
            }
            $wmDot = match($wmStatus) {
                'synced'  => ['color' => '#16a34a', 'label' => 'Sincronizat WinMentor'],
                'failed'  => ['color' => '#dc2626', 'label' => 'Eroare WinMentor'],
                'pending' => ['color' => '#f59e0b', 'label' => 'În așteptare WinMentor'],
                default   => ['color' => '#cbd5e1', 'label' => 'Nesincronizat'],
            };
        @endphp
        <a href="{{ route('warehouse.history.detail', $order) }}" style="text-decoration:none; color:inherit; display:block">
            <div class="wh-card" style="margin-bottom:10px; border-left:4px solid {{ $borderColor }}; display:flex; align-items:center; justify-content:space-between; gap:12px">
                <div style="min-width:0; flex:1">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:3px; flex-wrap:wrap">
                        <div style="font-weight:700; font-size:15px; color:#b91c1c">{{ $order->number }}</div>
                        @if($isPartial)
                            <span style="background:#f59e0b; color:white; font-size:10px; font-weight:700; padding:1px 6px; border-radius:6px">PARȚIAL ({{ $receptionCount }})</span>
                        @elseif($receptionCount > 1)
                            <span style="background:#16a34a; color:white; font-size:10px; font-weight:700; padding:1px 6px; border-radius:6px">{{ $receptionCount }} recepții</span>
                        @endif
                        <span title="{{ $wmDot['label'] }}"
                            style="width:10px; height:10px; border-radius:50%; background:{{ $wmDot['color'] }}; flex-shrink:0; display:inline-block"></span>
                    </div>
                    <div style="font-size:14px; font-weight:600; color:#1e293b; margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
                        {{ $order->supplier?->name ?? '—' }}
                    </div>
                    <div style="font-size:12px; color:#64748b; display:flex; gap:10px; flex-wrap:wrap">
                        <span>{{ $order->received_at?->format('d.m.Y') }}</span>
                        <span>{{ $order->items->count() }} produse</span>
                        @if($order->receivedBy)
                            <span>{{ $order->receivedBy->name }}</span>
                        @endif
                        <span style="color:{{ $wmDot['color'] }}; font-weight:600">{{ $wmDot['label'] }}</span>
                    </div>
                </div>
                <div style="font-size:18px; color:#94a3b8; flex-shrink:0">›</div>
            </div>
        </a>
        @endforeach

        @if($orders->hasPages())
        <div style="display:flex; justify-content:center; gap:8px; margin-top:16px; flex-wrap:wrap">
            @if($orders->onFirstPage())
                <span style="padding:8px 16px; border-radius:8px; background:#f1f5f9; color:#94a3b8; font-size:14px">‹ Anterior</span>
            @else
                <a href="{{ $orders->previousPageUrl() }}" style="padding:8px 16px; border-radius:8px; background:#e2e8f0; color:#475569; font-size:14px; text-decoration:none">‹ Anterior</a>
            @endif
            <span style="padding:8px 16px; font-size:14px; color:#64748b">{{ $orders->currentPage() }} / {{ $orders->lastPage() }}</span>
            @if($orders->hasMorePages())
                <a href="{{ $orders->nextPageUrl() }}" style="padding:8px 16px; border-radius:8px; background:#e2e8f0; color:#475569; font-size:14px; text-decoration:none">Următor ›</a>
            @else
                <span style="padding:8px 16px; border-radius:8px; background:#f1f5f9; color:#94a3b8; font-size:14px">Următor ›</span>
            @endif
        </div>
        @endif
    @endif
</div>
@endsection
