<x-filament-panels::page>

<div class="space-y-6">

    {{-- Carduri sumar --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        @php
            $cards = [
                ['label' => 'Total emailuri', 'value' => number_format($this->getTotalEmails()), 'color' => 'primary', 'icon' => 'heroicon-o-envelope'],
                ['label' => 'Procesate AI', 'value' => number_format($this->getProcessedEmails()), 'color' => 'success', 'icon' => 'heroicon-o-cpu-chip'],
                ['label' => 'Neprocessate', 'value' => number_format($this->getUnprocessedEmails()), 'color' => 'warning', 'icon' => 'heroicon-o-clock'],
                ['label' => 'Contacte totale', 'value' => number_format($this->getTotalContacts()), 'color' => 'info', 'icon' => 'heroicon-o-users'],
                ['label' => 'Descoperite auto', 'value' => number_format($this->getDiscoveredContacts()), 'color' => 'info', 'icon' => 'heroicon-o-magnifying-glass'],
                ['label' => 'Expeditori necunoscuți', 'value' => number_format($this->getUnknownSenders()), 'color' => 'danger', 'icon' => 'heroicon-o-question-mark-circle'],
            ];
        @endphp
        @foreach($cards as $card)
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 flex flex-col gap-1">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $card['value'] }}</p>
        </div>
        @endforeach
    </div>

    {{-- Volum lunar --}}
    @php $monthly = $this->getMonthlyVolume(); @endphp
    @if($monthly->isNotEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Volum emailuri per lună</h3>
        @php $maxTotal = $monthly->max('total') ?: 1; @endphp
        <div class="space-y-1.5">
            @foreach($monthly as $row)
            <div class="flex items-center gap-3 text-xs">
                <span class="w-14 text-gray-500 flex-shrink-0">{{ $row->month }}</span>
                <div class="flex-1 flex gap-0.5 h-5">
                    <div class="bg-primary-500 rounded-l h-full"
                         style="width: {{ $maxTotal > 0 ? round($row->received / $maxTotal * 100) : 0 }}%"
                         title="Primite: {{ $row->received }}"></div>
                    <div class="bg-blue-300 dark:bg-blue-700 rounded-r h-full"
                         style="width: {{ $maxTotal > 0 ? round($row->sent / $maxTotal * 100) : 0 }}%"
                         title="Trimise: {{ $row->sent }}"></div>
                </div>
                <span class="w-10 text-right font-medium text-gray-700 dark:text-gray-300">{{ $row->total }}</span>
                <span class="w-20 text-gray-400">
                    <span class="text-primary-500">▼{{ $row->received }}</span>
                    <span class="text-blue-400 ml-1">▲{{ $row->sent }}</span>
                </span>
            </div>
            @endforeach
        </div>
        <div class="mt-2 flex gap-4 text-xs text-gray-400">
            <span><span class="inline-block w-3 h-3 bg-primary-500 rounded mr-1"></span>Primite</span>
            <span><span class="inline-block w-3 h-3 bg-blue-300 dark:bg-blue-700 rounded mr-1"></span>Trimise</span>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Top furnizori --}}
        @php $topSuppliers = $this->getTopSuppliersByVolume(); @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Top furnizori după volum</h3>
            <div class="space-y-2">
                @forelse($topSuppliers as $row)
                <div class="flex items-center gap-3 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                            {{ $row['supplier']?->name ?? 'Necunoscut' }}
                        </p>
                        <p class="text-xs text-gray-400">
                            @if($row['last_email'])
                                Ultimul: {{ $row['last_email']->diffForHumans() }}
                            @endif
                            @if($row['freq_per_month'])
                                · {{ $row['freq_per_month'] }}/lună
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0 text-xs text-gray-500">
                        @if($row['with_attachments'] > 0)
                            <span title="Cu atașamente" class="text-gray-400">📎 {{ $row['with_attachments'] }}</span>
                        @endif
                        <span class="text-primary-600 font-semibold text-sm">{{ $row['total'] }}</span>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400">Nu există date.</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">

            {{-- Distribuție tipuri AI --}}
            @php $aiTypes = $this->getEmailTypeDistribution(); @endphp
            @if($aiTypes->isNotEmpty())
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Tipuri emailuri (analiză AI)</h3>
                @php $maxType = $aiTypes->max('cnt') ?: 1; @endphp
                <div class="space-y-2">
                    @foreach($aiTypes as $row)
                    @php
                        $typeLabels = [
                            'offer'                 => 'Ofertă',
                            'invoice'               => 'Factură',
                            'order_confirmation'    => 'Confirmare comandă',
                            'delivery_notification' => 'Notificare livrare',
                            'price_list'            => 'Listă prețuri',
                            'payment'               => 'Plată',
                            'complaint'             => 'Reclamație',
                            'inquiry'               => 'Informare',
                            'automated'             => 'Automat/Newsletter',
                            'general'               => 'General',
                        ];
                        $label = $typeLabels[$row->email_type] ?? $row->email_type;
                        $pct = round($row->cnt / $maxType * 100);
                    @endphp
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-32 text-gray-600 dark:text-gray-400 truncate">{{ $label }}</span>
                        <div class="flex-1 bg-gray-100 dark:bg-gray-800 rounded h-3">
                            <div class="bg-primary-500 rounded h-3" style="width: {{ $pct }}%"></div>
                        </div>
                        <span class="w-8 text-right font-medium text-gray-700 dark:text-gray-300">{{ $row->cnt }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Furnizori tăcuți --}}
            @php $silent = $this->getSilentSuppliers(60)->take(8); @endphp
            @if($silent->isNotEmpty())
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Furnizori tăcuți <span class="text-gray-400 font-normal">(>60 zile)</span></h3>
                <p class="text-xs text-gray-400 mb-3">Furnizori activi fără comunicare recentă</p>
                <div class="space-y-1.5">
                    @foreach($silent as $row)
                    <div class="flex items-center justify-between text-sm py-0.5">
                        <span class="text-gray-700 dark:text-gray-300 truncate">{{ $row['supplier']->name }}</span>
                        <span class="text-xs text-gray-400 flex-shrink-0 ml-2">
                            @if($row['last_email'])
                                {{ $row['days_silent'] }} zile
                            @else
                                niciodată
                            @endif
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </div>

    {{-- Expeditori necunoscuți --}}
    @php $unknown = $this->getUnknownSendersList(); @endphp
    @if($unknown->isNotEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Expeditori frecvenți neasociați unui furnizor</h3>
        <p class="text-xs text-gray-400 mb-4">Aceștia ar putea fi contacte de la furnizori existenți sau furnizori noi de adăugat.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2 font-medium">Email</th>
                        <th class="pb-2 font-medium">Nume detectat</th>
                        <th class="pb-2 font-medium text-right">Emailuri</th>
                        <th class="pb-2 font-medium text-right">Ultimul</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($unknown as $row)
                    <tr>
                        <td class="py-1.5 text-gray-700 dark:text-gray-300">{{ $row->from_email }}</td>
                        <td class="py-1.5 text-gray-500">{{ $row->from_name ?? '-' }}</td>
                        <td class="py-1.5 text-right font-medium text-gray-700 dark:text-gray-300">{{ $row->cnt }}</td>
                        <td class="py-1.5 text-right text-gray-400 text-xs">
                            {{ $row->last_seen ? \Carbon\Carbon::parse($row->last_seen)->diffForHumans() : '-' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>

</x-filament-panels::page>
