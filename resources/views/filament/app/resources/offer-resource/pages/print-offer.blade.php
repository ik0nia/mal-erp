@php
    /** @var \App\Models\Offer $offer */
    $offer = $record->loadMissing(['items.product', 'location', 'user']);
    $logo = file_exists(public_path('malinco-logo.png')) ? asset('malinco-logo.png') : null;
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
            <button type="button" class="offer-print-btn" onclick="window.print()">Print / Salvează PDF</button>
            <a class="offer-print-link" href="{{ \App\Filament\App\Resources\OfferResource::getUrl('view', ['record' => $offer]) }}">
                Înapoi la ofertă
            </a>
        </div>

        <div class="offer-print-sheet">
            @include('offers.document', ['offer' => $offer, 'logo' => $logo, 'linkProducts' => true])
        </div>
    </div>

    <style>
        .offer-print-root { padding: 0.75rem; }
        .offer-print-toolbar { display: flex; gap: 0.75rem; margin-bottom: 1rem; }
        .offer-print-btn, .offer-print-link {
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 0.5rem; border: 1px solid #d1d5db; background: #fff; color: #111827;
            padding: 0.45rem 0.9rem; text-decoration: none; font-size: 0.875rem; cursor: pointer;
        }
        .offer-print-btn { background: #c01722; color: #fff; border-color: #c01722; }
        .offer-print-sheet {
            margin: 0 auto; max-width: 820px; background: #fff;
            border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 24px 28px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.06);
        }
        @media print {
            body * { visibility: hidden !important; }
            .offer-print-root, .offer-print-root * { visibility: visible !important; }
            .offer-print-root { position: absolute; inset: 0; margin: 0; padding: 0; }
            .offer-print-sheet { border: 0; border-radius: 0; box-shadow: none; max-width: 100%; width: 100%; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            @page { size: A4; margin: 12mm; }
        }
    </style>

    @if(request()->boolean('auto_print'))
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 200);
                window.addEventListener('afterprint', function () {
                    if (window.opener) { window.close(); }
                }, { once: true });
            });
        </script>
    @endif
</x-filament-panels::page>
