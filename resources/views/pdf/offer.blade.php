<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 28px 56px 28px; }
        body { margin: 0; }
        .pdf-footer {
            position: fixed; bottom: -38px; left: 0; right: 0;
            border-top: 1px solid #e5e7eb; padding-top: 5px; color: #9ca3af;
            font-size: 8px; font-family: 'DejaVu Sans', sans-serif;
        }
        .pdf-footer table { width: 100%; border-collapse: collapse; }
    </style>
</head>
<body>
    @include('offers.document', ['offer' => $offer, 'logo' => $logo, 'linkProducts' => true])

    <div class="pdf-footer">
        <table><tr>
            <td>{{ $offer->location?->company_name ?: 'Malinco Prodex S.R.L.' }}@if($offer->location?->company_vat_number) · CUI {{ $offer->location->company_vat_number }}@endif</td>
            <td style="text-align:right;">Ofertă {{ $offer->number }} · {{ now()->format('d.m.Y H:i') }}</td>
        </tr></table>
    </div>
</body>
</html>
