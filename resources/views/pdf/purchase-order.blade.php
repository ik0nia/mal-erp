<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.4; }

  .page { padding: 18px 25px; }

  .header { display: table; width: 100%; border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 12px; }
  .header-left { display: table-cell; width: 55%; vertical-align: middle; }
  .header-right { display: table-cell; width: 45%; text-align: right; vertical-align: middle; }
  .company-sub { font-size: 9px; color: #6b7280; }
  .po-title { font-size: 16px; font-weight: bold; color: #1a1a1a; }
  .po-number { font-size: 12px; color: #dc2626; font-weight: bold; }
  .po-meta { font-size: 9px; color: #6b7280; margin-top: 2px; }

  .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold; margin-top: 4px; }
  .status-draft { background: #f3f4f6; color: #374151; }
  .status-approved { background: #dcfce7; color: #15803d; }
  .status-sent { background: #dbeafe; color: #dc2626; }
  .status-received { background: #dcfce7; color: #15803d; }
  .status-pending_approval { background: #fef9c3; color: #854d0e; }

  .parties { display: table; width: 100%; margin-bottom: 12px; }
  .party { display: table-cell; width: 48%; vertical-align: top; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 3px; padding: 7px 10px; }
  .party-spacer { display: table-cell; width: 4%; }
  .party-label { font-size: 8px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
  .party-name { font-size: 11px; font-weight: bold; color: #1a1a1a; }
  .party-detail { font-size: 9px; color: #4b5563; margin-top: 1px; }

  table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  table.items thead tr { background: #dc2626; color: white; }
  table.items thead th { padding: 6px 8px; text-align: left; font-size: 9px; font-weight: bold; }
  table.items thead th.right { text-align: right; }
  table.items tbody tr:nth-child(even) { background: #f8fafc; }
  table.items tbody td { padding: 5px 8px; font-size: 9px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  table.items tbody td.right { text-align: right; }
  table.items tbody td.muted { color: #6b7280; font-size: 8px; }
  table.items tfoot td { display: none; }

  .notes { margin-bottom: 10px; }
  .notes-label { font-size: 8px; font-weight: bold; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
  .notes-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 3px; padding: 6px 8px; font-size: 9px; color: #4b5563; }

  .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #e2e8f0; display: table; width: 100%; }
  .footer-left { display: table-cell; width: 50%; font-size: 8px; color: #9ca3af; }
  .footer-right { display: table-cell; width: 50%; text-align: right; font-size: 8px; color: #9ca3af; }

  .sig { margin-top: 20px; display: table; width: 100%; }
  .sig-cell { display: table-cell; width: 33%; text-align: center; }
  .sig-line { border-top: 1px solid #374151; margin: 0 15px; padding-top: 3px; font-size: 8px; color: #6b7280; }

  .sources-list { font-size: 8px; color: #6b7280; margin-top: 1px; }
</style>
</head>
<body>
<div class="page">

  {{-- Header --}}
  <div class="header">
    <div class="header-left">
      <div style="text-align:left;">
        <img src="file://{{ storage_path('app/public/malinco-logo.png') }}" alt="Malinco" style="height:36px;width:auto;display:block;">
        <div class="company-sub" style="margin-top:3px;">Malinco ERP — Modul Achiziții</div>
      </div>
    </div>
    <div class="header-right">
      <div class="po-title">COMANDĂ FURNIZOR</div>
      <div class="po-number">{{ $order->number }}</div>
      <div class="po-meta">Data: {{ $order->created_at->format('d.m.Y') }}
        @if($order->sent_at) &nbsp;|&nbsp; Trimisă: {{ $order->sent_at->format('d.m.Y') }}@endif
      </div>
      @php
        $statusLabels = \App\Models\PurchaseOrder::statusOptions();
        $statusLabel  = $statusLabels[$order->status] ?? $order->status;
        $statusClass  = 'status-' . $order->status;
      @endphp
      <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
    </div>
  </div>

  {{-- Parties --}}
  <div class="parties">
    <div class="party">
      <div class="party-label">Cumpărător</div>
      <div class="party-name">SC Malinco Prodex SRL</div>
      <div class="party-detail">Santandrei Nr. 311, jud. Bihor</div>
      <div class="party-detail">Tel: 0359 444 999 &nbsp;|&nbsp; malinco.ro</div>
      <div class="party-detail" style="margin-top:3px;">Responsabil: {{ $order->buyer?->name ?? '—' }}</div>
    </div>
    <div class="party-spacer"></div>
    <div class="party">
      <div class="party-label">Furnizor</div>
      <div class="party-name">{{ $order->supplier?->name ?? '—' }}</div>
      @if($order->supplier?->email)
        <div class="party-detail">{{ $order->supplier->email }}</div>
      @endif
      @if($order->supplier?->phone)
        <div class="party-detail">{{ $order->supplier->phone }}</div>
      @endif
      @php $primaryContact = $order->supplier?->contacts()->where('is_primary', true)->first() ?? $order->supplier?->contacts()->first(); @endphp
      @if($primaryContact)
        <div class="party-detail" style="margin-top:3px;">Persoană contact: <strong>{{ $primaryContact->name }}</strong></div>
        @if($primaryContact->email)
          <div class="party-detail">{{ $primaryContact->email }}</div>
        @endif
        @if($primaryContact->phone)
          <div class="party-detail">{{ $primaryContact->phone }}</div>
        @endif
      @endif
    </div>
  </div>

  {{-- Items table --}}
  <table class="items">
    <thead>
      <tr>
        <th style="width:5%">#</th>
        <th style="width:55%">Produs</th>
        <th style="width:20%">SKU furnizor</th>
        <th class="right" style="width:20%">Cant.</th>
      </tr>
    </thead>
    <tbody>
      @foreach($order->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>
          {{ $item->product_name }}
          @if($item->notes)
            <div class="muted">{{ $item->notes }}</div>
          @endif
          @if(!empty($item->sources_json))
            @php $sources = json_decode($item->sources_json, true) ?? [] @endphp
            @if(count($sources) > 0)
              <div class="sources-list">
                Necesar: {{ collect($sources)->pluck('request_number')->filter()->implode(', ') }}
              </div>
            @endif
          @endif
        </td>
        <td class="muted">{{ $item->supplier_sku ?: '—' }}</td>
        <td class="right">{{ number_format((float)$item->quantity, 0, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  {{-- Notes --}}
  @if($order->notes_supplier)
    <div class="notes">
      <div class="notes-label">Observații pentru furnizor</div>
      <div class="notes-box">{{ $order->notes_supplier }}</div>
    </div>
  @endif

  @if($order->notes_internal)
    <div class="notes">
      <div class="notes-label">Note interne</div>
      <div class="notes-box">{{ $order->notes_internal }}</div>
    </div>
  @endif

  {{-- Signatures --}}
  <div class="sig">
    <div class="sig-cell">
      <div class="sig-line">Emis de: {{ $order->buyer?->name ?? '—' }}</div>
    </div>
    <div class="sig-cell">
      <div class="sig-line">Aprobat de: {{ $order->approvedBy?->name ?? '—' }}</div>
    </div>
    <div class="sig-cell">
      <div class="sig-line">Confirmat furnizor: ___________</div>
    </div>
  </div>

  {{-- Footer --}}
  <div class="footer">
    <div class="footer-left">Generat din ERP Malinco — {{ now()->format('d.m.Y H:i') }}</div>
    <div class="footer-right">{{ $order->number }}</div>
  </div>

</div>
</body>
</html>
