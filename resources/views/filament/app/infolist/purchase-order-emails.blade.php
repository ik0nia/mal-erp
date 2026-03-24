@php
    $record = $getRecord();
    $record->loadMissing('supplier');
    $supplierId = $record->supplier_id;

    // Emailuri ±60 zile față de created_at al PO-ului
    $from = $record->created_at->subDays(30);
    $to   = $record->created_at->addDays(60);

    $emails = $supplierId
        ? \App\Models\EmailMessage::with('supplierContact')
            ->where('supplier_id', $supplierId)
            ->whereBetween('sent_at', [$from, $to])
            ->orderByDesc('sent_at')
            ->limit(12)
            ->get()
        : collect();
@endphp

@if($emails->isEmpty())
    <p class="text-sm text-gray-400 italic">
        @if(!$supplierId)
            Niciun furnizor asociat acestei comenzi.
        @else
            Nu există emailuri importate de la {{ $record->supplier?->name }} în intervalul comenzii.
        @endif
    </p>
@else
    <div class="space-y-1">
        <p class="text-xs text-gray-400 mb-2">
            Emailuri de la
            <span class="font-medium text-gray-600 dark:text-gray-300">{{ $record->supplier?->name }}</span>
            în intervalul {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}
        </p>

        @foreach($emails as $email)
        @php
            $ai = $email->agent_actions ?? [];
            $typeLabel = match($ai['type'] ?? null) {
                'offer'                 => ['Ofertă',    'bg-green-100 text-green-700'],
                'invoice'               => ['Factură',   'bg-yellow-100 text-yellow-700'],
                'order_confirmation'    => ['Confirmare','bg-blue-100 text-blue-700'],
                'delivery_notification' => ['Livrare',   'bg-sky-100 text-sky-700'],
                'price_list'            => ['Prețuri',   'bg-purple-100 text-purple-700'],
                'complaint'             => ['Reclamație','bg-red-100 text-red-700'],
                default => null,
            };
            $isSent = str_contains($email->imap_folder ?? '', 'Sent');
        @endphp
        <div class="flex items-start gap-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
            <div class="flex-shrink-0 w-24 text-xs text-gray-400">
                {{ $email->sent_at?->format('d.m.Y') }}
                @if($isSent)
                    <span class="block text-blue-400">trimis</span>
                @else
                    <span class="block text-green-500">primit</span>
                @endif
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    @if($typeLabel)
                        <span class="text-xs px-1.5 py-0.5 rounded {{ $typeLabel[1] }}">{{ $typeLabel[0] }}</span>
                    @endif
                    @if(!empty($ai['needs_reply']))
                        <span class="text-xs text-orange-500">↩ răspuns necesar</span>
                    @endif
                    @if(($ai['urgency'] ?? '') === 'high')
                        <span class="text-xs text-red-500">🔴 urgent</span>
                    @endif
                </div>
                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate mt-0.5">
                    {{ $email->subject ?: '(fără subiect)' }}
                </p>
                @if(!empty($ai['summary']))
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-2">{{ $ai['summary'] }}</p>
                @endif
                @if($email->supplierContact?->name)
                    <p class="text-xs text-gray-400 mt-0.5">Contact: {{ $email->supplierContact->name }}</p>
                @endif
            </div>
        </div>
        @endforeach
    </div>
@endif
