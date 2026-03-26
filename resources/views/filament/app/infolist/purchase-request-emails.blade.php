@php
    $record = $getRecord();

    $supplierIds = $record->items()
        ->whereNotNull('supplier_id')
        ->pluck('supplier_id')
        ->unique()
        ->values();

    $emails = $supplierIds->isEmpty() ? collect() :
        \App\Models\EmailMessage::with('supplier')
            ->whereIn('supplier_id', $supplierIds)
            ->whereIn('imap_folder', ['INBOX', 'INBOX.Sent'])
            ->orderByDesc('sent_at')
            ->limit(8)
            ->get();

    $supplierNames = \App\Models\Supplier::whereIn('id', $supplierIds)->pluck('name', 'id');
@endphp

@if($emails->isEmpty())
    <p style="font-size:14px;color:#9ca3af;font-style:italic">
        @if($supplierIds->isEmpty())
            Niciun furnizor asociat produselor din acest necesar.
        @else
            Nu există emailuri importate de la {{ $supplierNames->implode(', ') }}.
        @endif
    </p>
@else
    <div style="display:flex;flex-direction:column;gap:4px">
        <p style="font-size:12px;color:#9ca3af;margin-bottom:8px">
            Ultimele emailuri de la:
            <span style="font-weight:500;color:#4b5563">{{ $supplierNames->implode(', ') }}</span>
        </p>

        @foreach($emails as $email)
        @php
            $ai = $email->agent_actions ?? [];
            $typeLabel = match($ai['type'] ?? null) {
                'offer'                 => ['Ofertă',    'background:#dcfce7;color:#15803d'],
                'invoice'               => ['Factură',   'background:#fef9c3;color:#a16207'],
                'order_confirmation'    => ['Confirmare','background:#dbeafe;color:#1d4ed8'],
                'delivery_notification' => ['Livrare',   'background:#e0f2fe;color:#0369a1'],
                'price_list'            => ['Prețuri',   'background:#f3e8ff;color:#7e22ce'],
                'complaint'             => ['Reclamație','background:#fee2e2;color:#b91c1c'],
                default => null,
            };
            $isSent = $email->imap_folder === 'INBOX.Sent';
        @endphp
        <div style="display:flex;align-items:flex-start;gap:12px;padding:8px 0;border-bottom:1px solid #f3f4f6">
            <div style="flex-shrink:0;width:96px;font-size:12px;color:#9ca3af">
                {{ $email->sent_at?->format('d.m.Y') }}
                @if($isSent)
                    <span style="display:block;color:#60a5fa">trimis</span>
                @endif
            </div>
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    @if($typeLabel)
                        <span style="font-size:12px;padding:2px 6px;border-radius:4px;{{ $typeLabel[1] }}">{{ $typeLabel[0] }}</span>
                    @endif
                    @if(!empty($ai['needs_reply']))
                        <span style="font-size:12px;color:#f97316">↩ răspuns necesar</span>
                    @endif
                    @if(($ai['urgency'] ?? '') === 'high')
                        <span style="font-size:12px;color:#ef4444">🔴 urgent</span>
                    @endif
                </div>
                <p style="font-size:14px;font-weight:500;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px">
                    {{ $email->subject ?: '(fără subiect)' }}
                </p>
                @if(!empty($ai['summary']))
                    <p style="font-size:12px;color:#6b7280;margin-top:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ $ai['summary'] }}</p>
                @endif
            </div>
            <div style="flex-shrink:0;font-size:12px;color:#9ca3af">
                {{ $email->supplier?->name }}
            </div>
        </div>
        @endforeach
    </div>
@endif
