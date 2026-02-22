@php
    /** @var \App\Models\Offer $offer */
    $offer = $record->loadMissing(['items.product', 'location', 'user']);
    $location = $offer->location;
    $items = $offer->items;
@endphp

<x-filament-panels::page
    @class([
        'fi-resource-print-offer-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $offer->getKey(),
    ])
>
    <div class="offer-print-root">
        <div class="offer-print-toolbar no-print">
            <button type="button" class="offer-print-btn" onclick="window.print()">Print</button>
            <a class="offer-print-link" href="{{ \App\Filament\App\Resources\OfferResource::getUrl('view', ['record' => $offer]) }}" target="_blank">
                Preview
            </a>
        </div>

        <article class="offer-paper">
            <header class="offer-head">
                <div>
                    <h1>OFERTA COMERCIALA</h1>
                    <p class="muted">Nr: {{ $offer->number }}</p>
                    <p class="muted">Data: {{ $offer->created_at?->format('d.m.Y') }}</p>
                    <p class="muted">Valabila pana la: {{ $offer->valid_until?->format('d.m.Y') ?? '-' }}</p>
                </div>
                <div class="company-box">
                    <strong>{{ $location?->company_name ?: ($location?->name ?: 'Malinco ERP') }}</strong>
                    <div>{{ $location?->address ?: '-' }}</div>
                    <div>
                        {{ trim(($location?->city ?: '') . ' ' . ($location?->county ?: '') . ' ' . ($location?->company_postal_code ?: '')) }}
                    </div>
                    @if($location?->company_vat_number)
                        <div>CUI: {{ $location->company_vat_number }}</div>
                    @endif
                    @if(! is_null($location?->company_is_vat_payer))
                        <div>Plătitor TVA: {{ $location->company_is_vat_payer ? 'Da' : 'Nu' }}</div>
                    @endif
                    @if($location?->company_registration_number)
                        <div>Reg. Com.: {{ $location->company_registration_number }}</div>
                    @endif
                    @if($location?->company_phone)
                        <div>Telefon: {{ $location->company_phone }}</div>
                    @endif
                    @if($location?->company_bank)
                        <div>Bancă: {{ $location->company_bank }}</div>
                    @endif
                    @if($location?->company_bank_account)
                        <div>Cont: {{ $location->company_bank_account }}</div>
                    @endif
                </div>
            </header>

            <section class="client-box">
                <h2>Client</h2>
                <div><strong>{{ $offer->client_name }}</strong></div>
                <div>{{ $offer->client_company ?: '-' }}</div>
                <div>{{ $offer->client_email ?: '-' }}</div>
                <div>{{ $offer->client_phone ?: '-' }}</div>
            </section>

            <section>
                <table class="offer-table">
                    <thead>
                    <tr>
                        <th style="width: 42px">#</th>
                        <th>Produs</th>
                        <th style="width: 120px">SKU</th>
                        <th style="width: 80px; text-align: right">Cant.</th>
                        <th style="width: 120px; text-align: right">Pret unitar</th>
                        <th style="width: 90px; text-align: right">Disc. %</th>
                        <th style="width: 130px; text-align: right">Total linie</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($items as $index => $item)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                <div class="product-cell">
                                    @if($item->product?->main_image_url)
                                        <img src="{{ $item->product->main_image_url }}" alt="Produs">
                                    @endif
                                    <span>{{ html_entity_decode((string) $item->product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}</span>
                                </div>
                            </td>
                            <td>{{ $item->sku ?: '-' }}</td>
                            <td style="text-align: right">{{ number_format((float) $item->quantity, 3, '.', '') }}</td>
                            <td style="text-align: right">{{ number_format((float) $item->unit_price, 2, '.', ',') }} {{ $offer->currency }}</td>
                            <td style="text-align: right">{{ number_format((float) $item->discount_percent, 2, '.', ',') }}%</td>
                            <td style="text-align: right"><strong>{{ number_format((float) $item->line_total, 2, '.', ',') }} {{ $offer->currency }}</strong></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center">Nu exista produse in oferta.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </section>

            <section class="totals-box">
                <table>
                    <tr>
                        <td>Subtotal</td>
                        <td>{{ number_format((float) $offer->subtotal, 2, '.', ',') }} {{ $offer->currency }}</td>
                    </tr>
                    <tr>
                        <td>Discount total</td>
                        <td>{{ number_format((float) $offer->discount_total, 2, '.', ',') }} {{ $offer->currency }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total</td>
                        <td>{{ number_format((float) $offer->total, 2, '.', ',') }} {{ $offer->currency }}</td>
                    </tr>
                </table>
            </section>

            @if(filled($offer->notes))
                <section class="notes-box">
                    <h3>Observatii</h3>
                    <p>{{ $offer->notes }}</p>
                </section>
            @endif

            <footer class="signature-grid">
                <div>
                    <div class="sig-title">Reprezentant vanzari</div>
                    <div class="sig-line"></div>
                    <div class="muted">{{ $offer->user?->name ?: '-' }}</div>
                </div>
                <div>
                    <div class="sig-title">Client</div>
                    <div class="sig-line"></div>
                </div>
            </footer>
        </article>
    </div>

    <style>
        .offer-print-root {
            padding: 0.75rem;
        }
        .offer-print-toolbar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .offer-print-btn, .offer-print-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #111827;
            padding: 0.45rem 0.8rem;
            text-decoration: none;
            font-size: 0.875rem;
            cursor: pointer;
        }
        .offer-paper {
            margin: 0 auto;
            max-width: 900px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            color: #111827;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.06);
        }
        .offer-head {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }
        .offer-head h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .muted {
            color: #6b7280;
            font-size: 0.82rem;
        }
        .company-box {
            text-align: right;
            font-size: 0.88rem;
        }
        .client-box {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            background: #f9fafb;
        }
        .client-box h2 {
            margin: 0 0 0.35rem 0;
            font-size: 0.95rem;
        }
        .offer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
        }
        .offer-table th,
        .offer-table td {
            border: 1px solid #e5e7eb;
            padding: 0.45rem 0.5rem;
            vertical-align: middle;
        }
        .offer-table thead th {
            background: #f3f4f6;
            font-weight: 600;
        }
        .product-cell {
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .product-cell img {
            width: 28px;
            height: 28px;
            object-fit: cover;
            border-radius: 0.3rem;
            border: 1px solid #e5e7eb;
        }
        .totals-box {
            margin-top: 1rem;
            display: flex;
            justify-content: flex-end;
        }
        .totals-box table {
            width: 320px;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .totals-box td {
            border: 1px solid #e5e7eb;
            padding: 0.45rem 0.6rem;
        }
        .totals-box td:last-child {
            text-align: right;
            font-weight: 600;
        }
        .totals-box .total-row td {
            font-size: 1rem;
            background: #fef3c7;
        }
        .notes-box {
            margin-top: 1rem;
            font-size: 0.86rem;
        }
        .notes-box h3 {
            margin: 0 0 0.35rem 0;
            font-size: 0.9rem;
        }
        .signature-grid {
            margin-top: 1.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        .sig-title {
            font-size: 0.85rem;
            margin-bottom: 2rem;
        }
        .sig-line {
            border-bottom: 1px solid #9ca3af;
            margin-bottom: 0.4rem;
        }
        @media print {
            body * {
                visibility: hidden !important;
            }
            .offer-print-root,
            .offer-print-root * {
                visibility: visible !important;
            }
            .offer-print-root {
                position: absolute;
                inset: 0;
                margin: 0;
                padding: 0;
            }
            .offer-paper {
                border: 0;
                border-radius: 0;
                box-shadow: none;
                max-width: 100%;
                width: 100%;
                min-height: 0;
                margin: 0;
                padding: 10mm;
            }
            .no-print {
                display: none !important;
            }
            @page {
                size: A4;
                margin: 10mm;
            }
        }
    </style>
</x-filament-panels::page>
