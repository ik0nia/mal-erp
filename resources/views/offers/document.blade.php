@php
    /** @var \App\Models\Offer $offer */
    /** @var bool $linkProducts */
    $location = $offer->location;
    $cur = $offer->currency ?: 'RON';
    $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $linkProducts = $linkProducts ?? false;
    $vatRate = $pct(optional($offer->items->first())->vat_rate ?? 21);
    $netBefore = 0.0;
    foreach ($offer->items as $__it) {
        $__r = (float) $__it->vat_rate;
        $netBefore += (float) $__it->quantity * ($__r > 0 ? (float) $__it->unit_price / (1 + $__r / 100) : (float) $__it->unit_price);
    }
    $netDiscount = max(0, $netBefore - (float) $offer->subtotal_without_vat);
@endphp

<style>
    .od-paper { --brand: #c01722; --dark: #111827; color: #1f2937; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5px; }
    .od-paper * { box-sizing: border-box; }
    .od-paper table { border-collapse: collapse; width: 100%; }
    .od-paper h1, .od-paper h2, .od-paper h3 { margin: 0; }
    .od-muted { color: #6b7280; }
    .od-small { font-size: 9px; }
    .od-brandbar { background: var(--brand); height: 5px; }

    .od-head td { vertical-align: top; padding-top: 10px; }
    .od-logo { height: 40px; }
    .od-title { font-size: 19px; color: var(--brand); font-weight: bold; letter-spacing: .4px; margin-top: 8px; }
    .od-meta td { padding: 0; font-size: 10px; line-height: 1.45; }
    .od-meta .lbl { color: #6b7280; padding-right: 8px; }
    .od-company { font-size: 9.5px; line-height: 1.4; }
    .od-company .cname { font-size: 11.5px; font-weight: bold; color: var(--dark); }

    .od-sec { font-size: 9px; text-transform: uppercase; letter-spacing: .8px; color: var(--brand); font-weight: bold; margin: 12px 0 3px; }
    .od-client { background: #f9fafb; border: 1px solid #e5e7eb; border-left: 3px solid var(--brand); padding: 6px 10px; border-radius: 3px; }
    .od-client .cname { font-size: 11.5px; font-weight: bold; color: var(--dark); }

    .od-items thead th { background: var(--dark); color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; padding: 5px 6px; text-align: right; font-weight: bold; }
    .od-items thead th.l { text-align: left; }
    .od-items tbody td { padding: 3px 6px; border-bottom: 1px solid #eceef1; text-align: right; vertical-align: middle; }
    .od-items tbody td.l { text-align: left; }
    .od-items tbody tr.alt td { background: #fafafa; }
    .od-pname { font-weight: bold; color: var(--dark); }
    .od-pname a { color: var(--dark); text-decoration: none; border-bottom: 1px dotted var(--brand); }

    .od-totals { width: 250px; float: right; }
    .od-totals td { padding: 3px 8px; font-size: 10.5px; }
    .od-totals td.lbl { color: #6b7280; }
    .od-totals td.val { text-align: right; font-weight: bold; color: var(--dark); }
    .od-totals tr.grand td { background: var(--brand); color: #fff; font-size: 12.5px; padding: 6px 8px; }

    .od-vat td { padding: 1.5px 6px; font-size: 9px; }
    .od-vat thead td { color: #6b7280; border-bottom: 1px solid #e5e7eb; }
    .od-terms { border: 1px solid #e5e7eb; border-radius: 3px; padding: 6px 10px; font-size: 9px; }
    .od-terms ul { margin: 0; padding-left: 14px; }
    .od-terms li { margin-bottom: 1px; }
    .od-sign td { padding-top: 24px; }
    .od-sign .line { border-top: 1px solid #9ca3af; padding-top: 3px; width: 170px; }
</style>

<div class="od-paper">
    <div class="od-brandbar"></div>

    <table class="od-head">
        <tr>
            <td style="width:56%;">
                @if(!empty($logo))
                    <img src="{{ $logo }}" class="od-logo" alt="logo">
                @endif
                <div class="od-title">OFERTĂ COMERCIALĂ</div>
                <table class="od-meta" style="margin-top:4px;">
                    <tr><td class="lbl">Număr</td><td><strong>{{ $offer->number }}</strong></td></tr>
                    <tr><td class="lbl">Data</td><td>{{ $offer->created_at?->format('d.m.Y') }}</td></tr>
                    <tr><td class="lbl">Valabilă până la</td><td>{{ $offer->valid_until?->format('d.m.Y') ?? '—' }}</td></tr>
                </table>
            </td>
            <td style="width:44%; text-align:right;">
                <div class="od-company">
                    <div class="cname">{{ $location?->company_name ?: ($location?->name ?: 'Malinco Prodex S.R.L.') }}</div>
                    @if($location?->address)<div>{{ $location->address }}</div>@endif
                    <div>{{ trim(($location?->city ?: '') . ' ' . ($location?->county ?: '') . ' ' . ($location?->company_postal_code ?: '')) }}</div>
                    @if($location?->company_vat_number)<div>CUI: {{ $location->company_vat_number }}@if(!is_null($location?->company_is_vat_payer)) ({{ $location->company_is_vat_payer ? 'plătitor TVA' : 'neplătitor TVA' }})@endif</div>@endif
                    @if($location?->company_registration_number)<div>Reg. Com.: {{ $location->company_registration_number }}</div>@endif
                    @if($location?->company_phone)<div>Tel: {{ $location->company_phone }}</div>@endif
                    @if($location?->company_bank)<div>Bancă: {{ $location->company_bank }}</div>@endif
                    @if($location?->company_bank_account)<div class="od-small">IBAN: {{ $location->company_bank_account }}</div>@endif
                </div>
            </td>
        </tr>
    </table>

    <div class="od-sec">Către client</div>
    <div class="od-client">
        <table>
            <tr>
                <td style="width:60%;">
                    <span class="cname">{{ $offer->client_company ?: $offer->client_name }}</span>
                    @if($offer->client_company && $offer->client_name)<div class="od-small od-muted">În atenția: {{ $offer->client_name }}</div>@endif
                </td>
                <td style="width:40%; text-align:right;" class="od-small">
                    @if($offer->client_phone)<div>Tel: {{ $offer->client_phone }}</div>@endif
                    @if($offer->client_email)<div>{{ $offer->client_email }}</div>@endif
                </td>
            </tr>
        </table>
    </div>

    <table class="od-items" style="margin-top:8px;">
        <thead>
            <tr>
                <th class="l" style="width:22px;">#</th>
                <th class="l">Produs</th>
                <th style="width:56px;">Cant.</th>
                <th style="width:96px;">Preț unitar fără TVA</th>
                <th style="width:44px;">Cotă TVA</th>
                <th style="width:104px;">Valoare fără TVA</th>
            </tr>
        </thead>
        <tbody>
            @forelse($offer->items as $i => $item)
                @php
                    $url = $linkProducts ? $item->product?->site_url : null;
                    $rate = (float) $item->vat_rate;
                    $netUnit = $rate > 0 ? (float) $item->unit_price / (1 + $rate / 100) : (float) $item->unit_price;
                    $netLine = (float) $item->quantity * $netUnit;
                @endphp
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td class="l">{{ $i + 1 }}</td>
                    <td class="l">
                        <span class="od-pname">
                            @php $name = html_entity_decode((string) $item->product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8'); @endphp
                            @if($url)<a href="{{ $url }}" target="_blank">{{ $name }}</a>@else{{ $name }}@endif
                        </span>
                        @if($item->sku)<div class="od-small od-muted">Cod: {{ $item->sku }}</div>@endif
                    </td>
                    <td>{{ $qty($item->quantity) }} {{ $item->unit ?: 'buc' }}</td>
                    <td>{{ $fmt($netUnit) }}</td>
                    <td>{{ $pct($item->vat_rate) }}%</td>
                    <td><strong>{{ $fmt($netLine) }}</strong></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center; padding:10px;" class="od-muted">Oferta nu conține produse.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table style="margin-top:10px;">
        <tr>
            <td>
                <table class="od-totals">
                    <tr><td class="lbl">Valoare fără TVA</td><td class="val">{{ $fmt($netBefore) }} {{ $cur }}</td></tr>
                    @if((float) $offer->discount_total > 0)
                        <tr><td class="lbl">Discount acordat</td><td class="val" style="color:#c01722;">− {{ $fmt($netDiscount) }} {{ $cur }}</td></tr>
                        <tr><td class="lbl">Bază impozabilă</td><td class="val">{{ $fmt($offer->subtotal_without_vat) }} {{ $cur }}</td></tr>
                    @endif
                    <tr><td class="lbl">TVA ({{ $vatRate }}%)</td><td class="val">{{ $fmt($offer->vat_total) }} {{ $cur }}</td></tr>
                    <tr class="grand"><td class="lbl" style="color:#fff;">TOTAL DE PLATĂ</td><td class="val" style="color:#fff;">{{ $fmt($offer->total) }} {{ $cur }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if(($cur === 'RON' || $cur === 'LEI') && (float) $offer->total > 0)
        <div class="od-small od-muted" style="margin-top:6px; text-align:right;">
            Adică: {{ \App\Services\Offers\RoNumberToWords::money((float) $offer->total) }}.
        </div>
    @endif

    @if(filled($offer->notes))
        <div class="od-sec">Observații</div>
        <div class="od-terms">{{ $offer->notes }}</div>
    @endif

    <div class="od-sec">Condiții</div>
    <div class="od-terms">
        <ul>
            @foreach($offer->conditionLines() as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>

    <table class="od-sign">
        <tr>
            <td style="width:50%;">
                <div class="line">Reprezentant vânzări</div>
                <div class="od-small od-muted">{{ $offer->user?->name ?: '—' }}</div>
            </td>
            <td style="width:50%; text-align:right;">
                <div class="line" style="margin-left:auto;">Client (semnătură)</div>
            </td>
        </tr>
    </table>
</div>
