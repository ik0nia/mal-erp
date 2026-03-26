<x-filament-panels::page>
<div style="display: flex; flex-direction: column; gap: 1.25rem;">

    {{-- Stat cards --}}
    @php $stats = $this->getStats(); @endphp
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
        <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1rem;">
            <p style="font-size: 0.75rem; color: #6b7280;">Prețuri extrase total</p>
            <p style="font-size: 1.5rem; font-weight: 700; color: #111827;">{{ number_format($stats['total'], 0, '.', '') }}</p>
        </div>
        <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1rem;">
            <p style="font-size: 0.75rem; color: #6b7280;">Potrivite cu catalog</p>
            <p style="font-size: 1.5rem; font-weight: 700; color: #2563eb;">{{ number_format($stats['matched'], 0, '.', '') }}</p>
            @if($stats['total'] > 0)
            <p style="font-size: 0.75rem; color: #9ca3af;">{{ round($stats['matched']/$stats['total']*100) }}% din total</p>
            @endif
        </div>
        <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; padding: 1rem;">
            <p style="font-size: 0.75rem; color: #6b7280;">Mai ieftin cu >5% față de catalog</p>
            <p style="font-size: 1.5rem; font-weight: 700; color: #16a34a;">{{ number_format($stats['cheaper'], 0, '.', '') }}</p>
            <p style="font-size: 0.75rem; color: #9ca3af;">Potențial de negociere</p>
        </div>
    </div>

    {{-- Filtre --}}
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Caută produs..."
            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; width: 14rem; outline: none;"/>

        <select wire:model.live="filterSupplier"
            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; border: 1px solid #d1d5db; font-size: 0.875rem; outline: none;">
            <option value="">Toți furnizorii</option>
            @foreach($this->getSupplierOptions() as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        <div style="display: flex; gap: 0.25rem;">
            @foreach(['' => 'Toate', 'matched' => 'Potrivite catalog', 'unmatched' => 'Nepotrivite'] as $val => $label)
            <button wire:click="$set('filterMatched', '{{ $val }}')"
                style="font-size: 0.75rem; padding: 0.375rem 0.75rem; border-radius: 9999px; border: 1px solid {{ $filterMatched === $val ? '#8B1A1A' : '#d1d5db' }}; background: {{ $filterMatched === $val ? '#8B1A1A' : 'transparent' }}; color: {{ $filterMatched === $val ? '#fff' : '#4b5563' }}; cursor: pointer; transition: all 0.15s;">
                {{ $label }}
            </button>
            @endforeach
        </div>
    </div>

    {{-- Tabel --}}
    @php $quotes = $this->getQuotes(); @endphp
    <div style="background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; overflow: hidden;">
        @if($quotes->isEmpty())
            <div style="padding: 3rem; text-align: center; color: #9ca3af;">
                <x-filament::icon icon="heroicon-o-currency-dollar" style="width: 3rem; height: 3rem; margin: 0 auto 0.75rem; opacity: 0.3;"/>
                <p style="font-size: 0.875rem;">Nu există prețuri extrase încă.</p>
                <p style="font-size: 0.75rem; margin-top: 0.25rem; color: #d1d5db;">Procesarea AI a emailurilor va extrage prețurile automat.</p>
            </div>
        @else
        <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
            <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                <tr style="text-align: left; font-size: 0.75rem; color: #6b7280;">
                    <th style="padding: 0.625rem 1rem; font-weight: 500;">Furnizor</th>
                    <th style="padding: 0.625rem 1rem; font-weight: 500;">Produs (din email)</th>
                    <th style="padding: 0.625rem 1rem; font-weight: 500;">Produs catalog</th>
                    <th style="padding: 0.625rem 1rem; font-weight: 500; text-align: right;">Preț oferit</th>
                    <th style="padding: 0.625rem 1rem; font-weight: 500; text-align: right;">Preț catalog</th>
                    <th style="padding: 0.625rem 1rem; font-weight: 500; text-align: right;">Delta</th>
                    <th style="padding: 0.625rem 1rem; font-weight: 500;">Data ofertei</th>
                </tr>
            </thead>
            <tbody>
                @foreach($quotes as $row)
                @php $q = $row['quote']; @endphp
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 0.625rem 1rem;">
                        <span style="font-size: 0.875rem; font-weight: 500; color: #1f2937;">
                            {{ $q->supplier?->name ?? '—' }}
                        </span>
                    </td>
                    <td style="padding: 0.625rem 1rem;">
                        <span style="color: #374151;">{{ $q->product_name_raw }}</span>
                        @if($q->min_qty)
                            <span style="font-size: 0.75rem; color: #9ca3af; margin-left: 0.25rem;">min {{ $q->min_qty }}</span>
                        @endif
                    </td>
                    <td style="padding: 0.625rem 1rem;">
                        @if($q->product)
                            <span style="font-size: 0.75rem; background: #dbeafe; color: #1d4ed8; border-radius: 0.25rem; padding: 0.125rem 0.375rem;">
                                {{ Str::limit($q->product->name, 40) }}
                            </span>
                        @else
                            <span style="font-size: 0.75rem; color: #9ca3af; font-style: italic;">nepotrivit</span>
                        @endif
                    </td>
                    <td style="padding: 0.625rem 1rem; text-align: right; font-weight: 600; color: #111827;">
                        {{ number_format($q->unit_price, 2) }} <span style="font-size: 0.75rem; font-weight: 400; color: #9ca3af;">{{ $q->currency }}</span>
                    </td>
                    <td style="padding: 0.625rem 1rem; text-align: right;">
                        @if($row['currentPrice'])
                            <span style="color: #374151;">{{ number_format($row['currentPrice'], 2) }}</span>
                            <span style="font-size: 0.75rem; color: #9ca3af; margin-left: 0.125rem;">RON</span>
                        @else
                            <span style="color: #d1d5db;">—</span>
                        @endif
                    </td>
                    <td style="padding: 0.625rem 1rem; text-align: right;">
                        @if($row['delta'] !== null)
                            @php
                                $deltaStyle = match($row['deltaColor']) {
                                    'green' => 'background: #dcfce7; color: #15803d;',
                                    'red'   => 'background: #fee2e2; color: #b91c1c;',
                                    default => 'background: #f3f4f6; color: #4b5563;',
                                };
                            @endphp
                            <span style="font-size: 0.75rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-weight: 500; {{ $deltaStyle }}">
                                {{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }}%
                            </span>
                        @else
                            <span style="color: #d1d5db; font-size: 0.75rem;">—</span>
                        @endif
                    </td>
                    <td style="padding: 0.625rem 1rem; font-size: 0.75rem; color: #6b7280;">
                        {{ $q->quoted_at ? \Carbon\Carbon::parse($q->quoted_at)->format('d.m.Y') : '—' }}
                        @if($q->email)
                            <span style="margin-left: 0.25rem; color: #d1d5db; font-size: 0.75rem;" title="{{ $q->email->subject }}">↗</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding: 0.5rem 1rem; font-size: 0.75rem; color: #9ca3af; background: #f9fafb; border-top: 1px solid #f3f4f6;">
            {{ $quotes->count() }} prețuri afișate (max 200) · procesarea AI continuă în background
        </div>
        @endif
    </div>

</div>
</x-filament-panels::page>
