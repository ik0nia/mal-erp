<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
@page {
    margin: 15mm 12mm 18mm 12mm;
    size: A4 landscape;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 9pt; color: #1a1a1a; line-height: 1.4; }

/* ── HEADER ─────────────────────────────────────────────── */
.doc-header {
    background: #111827;
    color: #ffffff;
    padding: 10px 16px;
    margin-bottom: 14px;
}
.doc-header table { width: 100%; border-collapse: collapse; }
.doc-header td { vertical-align: middle; padding: 0; }
.doc-header .title-cell { width: 55%; }
.doc-header .logo-cell  { width: 20%; text-align: center; }
.doc-header .meta-cell  { width: 25%; text-align: right; }

.doc-title {
    font-size: 16pt;
    font-weight: bold;
    color: #ffffff;
    letter-spacing: 0.04em;
    line-height: 1.2;
}
.doc-subtitle {
    font-size: 9pt;
    color: #f87171;
    margin-top: 3px;
}
.logo-placeholder {
    display: inline-block;
    border: 2px solid #dc2626;
    color: #dc2626;
    font-size: 14pt;
    font-weight: bold;
    padding: 6px 14px;
    letter-spacing: 0.06em;
}
.meta-label { font-size: 7.5pt; color: #9ca3af; }
.meta-value { font-size: 9pt;  color: #ffffff; font-weight: bold; }

/* ── SHELF SECTION ──────────────────────────────────────── */
.shelf-section { margin-bottom: 18px; }

.shelf-header {
    padding: 7px 14px;
    color: #ffffff;
    margin-bottom: 10px;
}
.shelf-header table { width: 100%; border-collapse: collapse; }
.shelf-header td   { vertical-align: middle; padding: 0; }
.shelf-title-cell  { }
.shelf-total-cell  { text-align: right; }

.shelf-title    { font-size: 12pt; font-weight: bold; color: #ffffff; }
.shelf-subtitle { font-size: 8.5pt; color: rgba(255,255,255,0.80); margin-top: 2px; }
.shelf-total-box {
    display: inline-block;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.40);
    padding: 4px 12px;
    font-size: 10pt;
    font-weight: bold;
    color: #ffffff;
}
.shelf-total-label { font-size: 7.5pt; color: rgba(255,255,255,0.75); }

/* Shelf color variants — Malinco: roșu / gri închis / roșu închis */
.shelf-blue   { background: #991b1b; }
.shelf-green  { background: #1f2937; }
.shelf-orange { background: #7f1d1d; }

/* ── PRODUCT GRID (3 cards per row via table) ────────────── */
.product-grid { width: 100%; border-collapse: collapse; }
.product-grid td { vertical-align: top; padding: 4px; width: 33.33%; }

.product-card {
    border: 1px solid #dde4ec;
    background: #ffffff;
    padding: 8px 8px 7px 8px;
    page-break-inside: avoid;
}
.card-img-wrap {
    text-align: center;
    height: 84px;
    overflow: hidden;
    margin-bottom: 6px;
    background: #f8fafc;
    border-bottom: 1px solid #eef1f5;
}
.card-img-wrap img {
    max-height: 80px;
    max-width: 100%;
    display: block;
    margin: 2px auto 0 auto;
}
.card-img-placeholder {
    height: 80px;
    line-height: 80px;
    text-align: center;
    font-size: 7.5pt;
    color: #c0ccd8;
    background: #f0f4f8;
}
.card-name {
    font-size: 8.5pt;
    font-weight: bold;
    color: #1a1a1a;
    line-height: 1.3;
    min-height: 24px;
    max-height: 33px;
    overflow: hidden;
    margin-bottom: 4px;
}
.card-sku {
    font-size: 7.5pt;
    color: #8094aa;
    margin-bottom: 5px;
}
.card-details { width: 100%; border-collapse: collapse; }
.card-details td { padding: 1px 0; vertical-align: middle; }
.detail-label { font-size: 7.5pt; color: #6b7280; width: 52%; }
.detail-value { font-size: 8pt;   color: #1a1a1a; text-align: right; }
.detail-price { font-size: 8.5pt; color: #dc2626; font-weight: bold; }
.detail-total { font-size: 9pt;   color: #111827; font-weight: bold; }
.card-note {
    margin-top: 5px;
    font-size: 7.5pt;
    color: #7c5000;
    background: #fff8ee;
    border-left: 2px solid #f59e0b;
    padding: 2px 5px;
}

/* ── GRAND TOTAL PAGE ───────────────────────────────────── */
.grand-total-wrap { margin-top: 30px; }
.grand-total-title {
    font-size: 14pt;
    font-weight: bold;
    color: #111827;
    border-bottom: 2px solid #dc2626;
    padding-bottom: 6px;
    margin-bottom: 14px;
}

table.summary-table {
    width: 62%;
    margin-left: auto;
    border-collapse: collapse;
}
table.summary-table thead tr { background: #111827; color: #ffffff; }
table.summary-table thead th { padding: 7px 12px; font-size: 9pt; text-align: left; }
table.summary-table thead th.right { text-align: right; }
table.summary-table tbody td { padding: 7px 12px; font-size: 9pt; border-bottom: 1px solid #dde4ec; }
table.summary-table tbody td.right { text-align: right; }
table.summary-table tbody tr:nth-child(even) { background: #f4f7fb; }
.summary-shelf-color { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 5px; vertical-align: middle; }
.summary-total-row td { background: #111827; color: #ffffff; font-weight: bold; font-size: 10pt; padding: 8px 12px; border: none; }
.summary-total-row td.right { text-align: right; }
.summary-vat-note {
    text-align: right;
    font-size: 7.5pt;
    color: #8094aa;
    margin-top: 6px;
    padding-right: 2px;
}

/* ── FOOTER ─────────────────────────────────────────────── */
.page-footer {
    margin-top: 20px;
    padding-top: 7px;
    border-top: 1px solid #dde4ec;
}
.page-footer table { width: 100%; border-collapse: collapse; }
.page-footer td   { font-size: 7.5pt; color: #9ca3af; vertical-align: middle; }
.footer-right     { text-align: right; }

/* ── UTILITIES ──────────────────────────────────────────── */
.page-break { page-break-before: always; }
.text-right { text-align: right; }
</style>
</head>
<body>

{{-- ================================================================
     DOCUMENT HEADER (repeated via first-shelf page)
     ================================================================ --}}
<div class="doc-header">
    <table>
        <tr>
            <td class="title-cell">
                <div class="doc-title">PROPUNERE APROVIZIONARE TOYA</div>
                <div class="doc-subtitle">Plan de stoc rafturi — unelte și accesorii profesionale</div>
            </td>
            <td class="logo-cell">
                <div class="logo-placeholder">TOYA</div>
            </td>
            <td class="meta-cell">
                <div class="meta-label">Data generării</div>
                <div class="meta-value">{{ $generated_at }}</div>
                <div style="margin-top:6px;">
                    <div class="meta-label">Total rafturi</div>
                    <div class="meta-value">{{ count($shelves) }} rafturi</div>
                </div>
                <div style="margin-top:6px;">
                    <div class="meta-label">TOTAL GENERAL (fără TVA)</div>
                    <div class="meta-value" style="font-size:11pt;">{{ number_format($grand_total, 2, ',', '.') }} lei</div>
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- ================================================================
     SHELF SECTIONS
     ================================================================ --}}
@php
    $shelfColors = ['shelf-blue', 'shelf-green', 'shelf-orange'];
    $accentColors = ['#991b1b', '#1f2937', '#7f1d1d'];
@endphp

@foreach($shelves as $shelfIndex => $shelf)
    @php
        $colorClass  = $shelfColors[$shelfIndex % count($shelfColors)];
        $accentColor = $accentColors[$shelfIndex % count($accentColors)];
        $products    = $shelf['products'] ?? [];

        // Build rows of 3
        $rows = array_chunk($products, 3);
    @endphp

    {{-- Page break before every shelf except the first --}}
    @if($shelfIndex > 0)
        <div class="page-break"></div>
        {{-- Repeat compact header on subsequent pages --}}
        <div class="doc-header" style="margin-bottom:14px;">
            <table>
                <tr>
                    <td class="title-cell">
                        <div class="doc-title" style="font-size:12pt;">PROPUNERE APROVIZIONARE TOYA</div>
                        <div class="doc-subtitle">{{ $generated_at }}</div>
                    </td>
                    <td class="logo-cell">
                        <div class="logo-placeholder" style="font-size:11pt;padding:4px 10px;">TOYA</div>
                    </td>
                    <td class="meta-cell">
                        <div class="meta-label">TOTAL GENERAL (fără TVA)</div>
                        <div class="meta-value">{{ number_format($grand_total, 2, ',', '.') }} lei</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <div class="shelf-section">

        {{-- Shelf header bar --}}
        <div class="shelf-header {{ $colorClass }}">
            <table>
                <tr>
                    <td class="shelf-title-cell">
                        <div class="shelf-title">{{ $shelf['title'] }}</div>
                        @if(!empty($shelf['subtitle']))
                            <div class="shelf-subtitle">{{ $shelf['subtitle'] }}</div>
                        @endif
                    </td>
                    <td class="shelf-total-cell">
                        <div class="shelf-total-label">Total raft (fără TVA)</div>
                        <div class="shelf-total-box">{{ number_format($shelf['total_ron'] ?? 0, 2, ',', '.') }} lei</div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Story / justification --}}
        @if(!empty($shelf['story']))
            <div style="background:#f4f7fb;border-left:3px solid {{ $accentColor }};padding:7px 12px;margin-bottom:10px;font-size:8pt;color:#374151;line-height:1.5;">
                <strong style="color:{{ $accentColor }};">De ce acest raft?</strong>
                {{ $shelf['story'] }}
            </div>
        @endif

        {{-- Product grid --}}
        @if(count($products) > 0)
            <table class="product-grid">
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $product)
                            <td>
                                <div class="product-card">

                                    {{-- Image --}}
                                    <div class="card-img-wrap">
                                        @if(!empty($product['image_url']))
                                            <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}">
                                        @else
                                            <div class="card-img-placeholder">fără imagine</div>
                                        @endif
                                    </div>

                                    {{-- Name --}}
                                    <div class="card-name">{{ $product['name'] }}</div>

                                    {{-- SKU --}}
                                    <div class="card-sku">Cod furnizor: <strong>{{ $product['supplier_sku'] ?? '—' }}</strong></div>

                                    {{-- Details --}}
                                    <table class="card-details">
                                        <tr>
                                            <td class="detail-label">Preț unitar (fără TVA)</td>
                                            <td class="detail-value detail-price">{{ number_format($product['unit_price'] ?? 0, 2, ',', '.') }} lei</td>
                                        </tr>
                                        <tr>
                                            <td class="detail-label">Cantitate</td>
                                            <td class="detail-value">{{ $product['qty'] ?? 0 }} buc</td>
                                        </tr>
                                        <tr>
                                            <td class="detail-label">Total linie</td>
                                            <td class="detail-value detail-total">{{ number_format($product['line_total'] ?? 0, 2, ',', '.') }} lei</td>
                                        </tr>
                                    </table>

                                    {{-- Optional note --}}
                                    @if(!empty($product['note']))
                                        <div class="card-note">{{ $product['note'] }}</div>
                                    @endif

                                </div>
                            </td>
                        @endforeach

                        {{-- Pad row to always have 3 cells --}}
                        @for($pad = count($row); $pad < 3; $pad++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        @else
            <p style="font-size:8.5pt;color:#8094aa;padding:10px 4px;">Niciun produs în acest raft.</p>
        @endif

        {{-- Section total (right-aligned, colored) --}}
        <table style="width:100%;border-collapse:collapse;margin-top:8px;">
            <tr>
                <td style="width:65%;"></td>
                <td style="width:35%;">
                    <table style="width:100%;border-collapse:collapse;background:{{ $accentColor }};padding:6px 12px;">
                        <tr>
                            <td style="padding:6px 12px;font-size:8.5pt;color:rgba(255,255,255,0.80);">
                                Total {{ $shelf['title'] }} (fără TVA)
                            </td>
                            <td style="padding:6px 12px;font-size:11pt;font-weight:bold;color:#ffffff;text-align:right;">
                                {{ number_format($shelf['total_ron'] ?? 0, 2, ',', '.') }} lei
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

    </div>

    {{-- Per-page footer --}}
    <div class="page-footer">
        <table>
            <tr>
                <td>Prețuri fără TVA &bull; Discount comercial 30% aplicat &bull; Toya Romania</td>
                <td class="footer-right">{{ $generated_at }}</td>
            </tr>
        </table>
    </div>

@endforeach

{{-- ================================================================
     FINAL PAGE — GRAND TOTAL SUMMARY
     ================================================================ --}}
<div class="page-break"></div>

<div class="doc-header" style="margin-bottom:24px;">
    <table>
        <tr>
            <td class="title-cell">
                <div class="doc-title" style="font-size:12pt;">PROPUNERE APROVIZIONARE TOYA</div>
                <div class="doc-subtitle">Sumar financiar — toate rafturile</div>
            </td>
            <td class="logo-cell">
                <div class="logo-placeholder" style="font-size:11pt;padding:4px 10px;">TOYA</div>
            </td>
            <td class="meta-cell">
                <div class="meta-label">Data generării</div>
                <div class="meta-value">{{ $generated_at }}</div>
            </td>
        </tr>
    </table>
</div>

<div class="grand-total-wrap">

    <div class="grand-total-title">Sumar financiar propunere</div>

    <table class="summary-table">
        <thead>
            <tr>
                <th>Raft</th>
                <th>Descriere</th>
                <th class="right">Nr. produse</th>
                <th class="right">Total (fără TVA)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($shelves as $idx => $shelf)
                @php
                    $bg  = $accentColors[$idx % count($accentColors)];
                    $cnt = count($shelf['products'] ?? []);
                @endphp
                <tr>
                    <td>
                        <span class="summary-shelf-color" style="background:{{ $bg }};"></span>
                        <strong>{{ $shelf['title'] }}</strong>
                    </td>
                    <td style="color:#6b7280;">{{ $shelf['subtitle'] ?? '—' }}</td>
                    <td class="right">{{ $cnt }} produse</td>
                    <td class="right" style="font-weight:bold;color:{{ $bg }};">
                        {{ number_format($shelf['total_ron'] ?? 0, 2, ',', '.') }} lei
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="summary-total-row">
                <td colspan="2" style="background:#111827;color:#fff;font-weight:bold;padding:8px 12px;">
                    TOTAL GENERAL
                </td>
                <td class="right" style="background:#111827;color:#fff;font-weight:bold;padding:8px 12px;">
                    {{ $shelves->sum(fn($s) => count($s['products'] ?? [])) }} produse
                </td>
                <td class="right" style="background:#dc2626;color:#fff;font-size:12pt;font-weight:bold;padding:8px 12px;">
                    {{ number_format($grand_total, 2, ',', '.') }} lei
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="summary-vat-note">* Toate prețurile sunt exprimate fără TVA. TVA 21% se adaugă la facturare.</div>

    {{-- TVA estimate box --}}
    <table style="width:62%;margin-left:auto;border-collapse:collapse;margin-top:16px;">
        <tr>
            <td style="padding:8px 12px;font-size:8.5pt;color:#6b7280;background:#f4f7fb;border:1px solid #dde4ec;">
                Valoare fără TVA
            </td>
            <td style="padding:8px 12px;font-size:9pt;text-align:right;background:#f4f7fb;border:1px solid #dde4ec;">
                {{ number_format($grand_total, 2, ',', '.') }} lei
            </td>
        </tr>
        <tr>
            <td style="padding:8px 12px;font-size:8.5pt;color:#6b7280;background:#ffffff;border:1px solid #dde4ec;">
                TVA 21%
            </td>
            <td style="padding:8px 12px;font-size:9pt;text-align:right;background:#ffffff;border:1px solid #dde4ec;">
                {{ number_format($grand_total * 0.21, 2, ',', '.') }} lei
            </td>
        </tr>
        <tr>
            <td style="padding:9px 12px;font-size:10pt;font-weight:bold;color:#111827;background:#fef2f2;border:1px solid #dc2626;">
                TOTAL CU TVA
            </td>
            <td style="padding:9px 12px;font-size:11pt;font-weight:bold;color:#111827;text-align:right;background:#fef2f2;border:1px solid #dc2626;">
                {{ number_format($grand_total * 1.21, 2, ',', '.') }} lei
            </td>
        </tr>
    </table>

    {{-- Signature / approval area --}}
    <table style="width:100%;border-collapse:collapse;margin-top:36px;">
        <tr>
            <td style="width:33%;text-align:center;padding:0 20px;">
                <div style="border-top:1px solid #374151;padding-top:4px;font-size:8pt;color:#6b7280;">
                    Întocmit
                </div>
            </td>
            <td style="width:33%;text-align:center;padding:0 20px;">
                <div style="border-top:1px solid #374151;padding-top:4px;font-size:8pt;color:#6b7280;">
                    Verificat
                </div>
            </td>
            <td style="width:33%;text-align:center;padding:0 20px;">
                <div style="border-top:1px solid #374151;padding-top:4px;font-size:8pt;color:#6b7280;">
                    Aprobat
                </div>
            </td>
        </tr>
    </table>

</div>

<div class="page-footer" style="margin-top:28px;">
    <table>
        <tr>
            <td>Prețuri fără TVA &bull; Discount comercial 30% aplicat &bull; Toya Romania</td>
            <td class="footer-right">{{ $generated_at }}</td>
        </tr>
    </table>
</div>

</body>
</html>
