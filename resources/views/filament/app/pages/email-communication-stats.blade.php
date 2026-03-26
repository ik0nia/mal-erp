<x-filament-panels::page>

<div style="display: flex; flex-direction: column; gap: 1.5rem;">

    {{-- Carduri sumar --}}
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
        @php
            $cards = [
                ['label' => 'Total emailuri', 'value' => number_format($this->getTotalEmails(), 0, '.', ''), 'color' => '#8B1A1A', 'icon' => 'heroicon-o-envelope'],
                ['label' => 'Procesate AI', 'value' => number_format($this->getProcessedEmails(), 0, '.', ''), 'color' => '#16a34a', 'icon' => 'heroicon-o-cpu-chip'],
                ['label' => 'Neprocessate', 'value' => number_format($this->getUnprocessedEmails(), 0, '.', ''), 'color' => '#d97706', 'icon' => 'heroicon-o-clock'],
                ['label' => 'Contacte totale', 'value' => number_format($this->getTotalContacts(), 0, '.', ''), 'color' => '#2563eb', 'icon' => 'heroicon-o-users'],
                ['label' => 'Descoperite auto', 'value' => number_format($this->getDiscoveredContacts(), 0, '.', ''), 'color' => '#2563eb', 'icon' => 'heroicon-o-magnifying-glass'],
                ['label' => 'Expeditori necunoscuți', 'value' => number_format($this->getUnknownSenders(), 0, '.', ''), 'color' => '#dc2626', 'icon' => 'heroicon-o-question-mark-circle'],
            ];
        @endphp
        @foreach($cards as $card)
        <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1rem; display: flex; flex-direction: column; gap: 0.25rem;">
            <p style="font-size: 0.75rem; color: #6b7280;">{{ $card['label'] }}</p>
            <p style="font-size: 1.5rem; font-weight: 700; color: #111827;">{{ $card['value'] }}</p>
        </div>
        @endforeach
    </div>

    {{-- Volum lunar --}}
    @php $monthly = $this->getMonthlyVolume(); @endphp
    @if($monthly->isNotEmpty())
    <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1.25rem;">
        <h3 style="font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">Volum emailuri per lună</h3>
        @php $maxTotal = $monthly->max('total') ?: 1; @endphp
        <div style="display: flex; flex-direction: column; gap: 0.375rem;">
            @foreach($monthly as $row)
            <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.75rem;">
                <span style="width: 3.5rem; color: #6b7280; flex-shrink: 0;">{{ $row->month }}</span>
                <div style="flex: 1; display: flex; gap: 2px; height: 1.25rem;">
                    <div style="background: #8B1A1A; border-radius: 0.25rem 0 0 0.25rem; height: 100%; width: {{ $maxTotal > 0 ? round($row->received / $maxTotal * 100) : 0 }}%"
                         title="Primite: {{ $row->received }}"></div>
                    <div style="background: #93c5fd; border-radius: 0 0.25rem 0.25rem 0; height: 100%; width: {{ $maxTotal > 0 ? round($row->sent / $maxTotal * 100) : 0 }}%"
                         title="Trimise: {{ $row->sent }}"></div>
                </div>
                <span style="width: 2.5rem; text-align: right; font-weight: 500; color: #374151;">{{ $row->total }}</span>
                <span style="width: 5rem; color: #9ca3af;">
                    <span style="color: #8B1A1A;">▼{{ $row->received }}</span>
                    <span style="color: #60a5fa; margin-left: 0.25rem;">▲{{ $row->sent }}</span>
                </span>
            </div>
            @endforeach
        </div>
        <div style="margin-top: 0.5rem; display: flex; gap: 1rem; font-size: 0.75rem; color: #9ca3af;">
            <span><span style="display: inline-block; width: 0.75rem; height: 0.75rem; background: #8B1A1A; border-radius: 0.25rem; margin-right: 0.25rem;"></span>Primite</span>
            <span><span style="display: inline-block; width: 0.75rem; height: 0.75rem; background: #93c5fd; border-radius: 0.25rem; margin-right: 0.25rem;"></span>Trimise</span>
        </div>
    </div>
    @endif

    <div style="display: grid; grid-template-columns: repeat(1, 1fr); gap: 1.5rem;">

        {{-- Top furnizori --}}
        @php $topSuppliers = $this->getTopSuppliersByVolume(); @endphp
        <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1.25rem;">
            <h3 style="font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">Top furnizori după volum</h3>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                @forelse($topSuppliers as $row)
                <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.375rem 0; border-bottom: 1px solid #f3f4f6;">
                    <div style="flex: 1; min-width: 0;">
                        <p style="font-size: 0.875rem; font-weight: 500; color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            {{ $row['supplier']?->name ?? 'Necunoscut' }}
                        </p>
                        <p style="font-size: 0.75rem; color: #9ca3af;">
                            @if($row['last_email'])
                                Ultimul: {{ $row['last_email']->diffForHumans() }}
                            @endif
                            @if($row['freq_per_month'])
                                · {{ $row['freq_per_month'] }}/lună
                            @endif
                        </p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; font-size: 0.75rem; color: #6b7280;">
                        @if($row['with_attachments'] > 0)
                            <span title="Cu atașamente" style="color: #9ca3af;">📎 {{ $row['with_attachments'] }}</span>
                        @endif
                        <span style="color: #8B1A1A; font-weight: 600; font-size: 0.875rem;">{{ $row['total'] }}</span>
                    </div>
                </div>
                @empty
                <p style="font-size: 0.875rem; color: #9ca3af;">Nu există date.</p>
                @endforelse
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.5rem;">

            {{-- Distribuție tipuri AI --}}
            @php $aiTypes = $this->getEmailTypeDistribution(); @endphp
            @if($aiTypes->isNotEmpty())
            <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1.25rem;">
                <h3 style="font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">Tipuri emailuri (analiză AI)</h3>
                @php $maxType = $aiTypes->max('cnt') ?: 1; @endphp
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
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
                    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.75rem;">
                        <span style="width: 8rem; color: #4b5563; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $label }}</span>
                        <div style="flex: 1; background: #f3f4f6; border-radius: 0.25rem; height: 0.75rem;">
                            <div style="background: #8B1A1A; border-radius: 0.25rem; height: 0.75rem; width: {{ $pct }}%;"></div>
                        </div>
                        <span style="width: 2rem; text-align: right; font-weight: 500; color: #374151;">{{ $row->cnt }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Furnizori tăcuți --}}
            @php $silent = $this->getSilentSuppliers(60)->take(8); @endphp
            @if($silent->isNotEmpty())
            <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1.25rem;">
                <h3 style="font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 0.25rem;">Furnizori tăcuți <span style="color: #9ca3af; font-weight: 400;">(>60 zile)</span></h3>
                <p style="font-size: 0.75rem; color: #9ca3af; margin-bottom: 0.75rem;">Furnizori activi fără comunicare recentă</p>
                <div style="display: flex; flex-direction: column; gap: 0.375rem;">
                    @foreach($silent as $row)
                    <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.875rem; padding: 0.125rem 0;">
                        <span style="color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['supplier']->name }}</span>
                        <span style="font-size: 0.75rem; color: #9ca3af; flex-shrink: 0; margin-left: 0.5rem;">
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
    <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1.25rem;">
        <h3 style="font-size: 0.875rem; font-weight: 600; color: #374151; margin-bottom: 0.25rem;">Expeditori frecvenți neasociați unui furnizor</h3>
        <p style="font-size: 0.75rem; color: #9ca3af; margin-bottom: 1rem;">Aceștia ar putea fi contacte de la furnizori existenți sau furnizori noi de adăugat.</p>
        <div style="overflow-x: auto;">
            <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; font-size: 0.75rem; color: #9ca3af; border-bottom: 1px solid #e5e7eb;">
                        <th style="padding-bottom: 0.5rem; font-weight: 500;">Email</th>
                        <th style="padding-bottom: 0.5rem; font-weight: 500;">Nume detectat</th>
                        <th style="padding-bottom: 0.5rem; font-weight: 500; text-align: right;">Emailuri</th>
                        <th style="padding-bottom: 0.5rem; font-weight: 500; text-align: right;">Ultimul</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($unknown as $row)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 0.375rem 0; color: #374151;">{{ $row->from_email }}</td>
                        <td style="padding: 0.375rem 0; color: #6b7280;">{{ $row->from_name ?? '-' }}</td>
                        <td style="padding: 0.375rem 0; text-align: right; font-weight: 500; color: #374151;">{{ $row->cnt }}</td>
                        <td style="padding: 0.375rem 0; text-align: right; color: #9ca3af; font-size: 0.75rem;">
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
