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
    <p class="text-sm text-gray-400 italic">
        @if($supplierIds->isEmpty())
            Niciun furnizor asociat produselor din acest necesar.
        @else
            Nu există emailuri importate de la {{ $supplierNames->implode(', ') }}.
        @endif
    </p>
@else
    <div class="space-y-1">
        <p class="text-xs text-gray-400 mb-2">
            Ultimele emailuri de la:
            <span class="font-medium text-gray-600 dark:text-gray-300">{{ $supplierNames->implode(', ') }}</span>
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
            $isSent = $email->imap_folder === 'INBOX.Sent';
        @endphp
        <div class="flex items-start gap-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
            <div class="flex-shrink-0 w-24 text-xs text-gray-400">
                {{ $email->sent_at?->format('d.m.Y') }}
                @if($isSent)
                    <span class="block text-blue-400">trimis</span>
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
            </div>
            <div class="flex-shrink-0 text-xs text-gray-400">
                {{ $email->supplier?->name }}
            </div>
        </div>
        @endforeach
    </div>
@endif
