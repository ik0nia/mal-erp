<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a1a; }
    h1 { font-size: 16px; color: #1e3a5f; margin-bottom: 4px; }
    h2 { font-size: 12px; color: #1e3a5f; margin: 14px 0 4px 0; border-bottom: 2px solid #1e3a5f; padding-bottom: 3px; }
    h3 { font-size: 10px; color: #2c5282; margin: 8px 0 3px 0; }
    .header { background: #1e3a5f; color: white; padding: 12px 16px; margin-bottom: 14px; }
    .header h1 { color: white; }
    .header p { font-size: 9px; color: #b8d4f0; margin-top: 3px; }
    .stats-grid { display: table; width: 100%; margin-bottom: 12px; border-collapse: separate; border-spacing: 4px; }
    .stat-box { display: table-cell; background: #f0f4f8; border: 1px solid #cbd5e0; border-radius: 4px; padding: 8px 10px; text-align: center; width: 16%; }
    .stat-box .val { font-size: 20px; font-weight: bold; color: #1e3a5f; }
    .stat-box .lbl { font-size: 8px; color: #4a5568; margin-top: 2px; }
    .stat-box.green .val { color: #276749; }
    .stat-box.orange .val { color: #c05621; }
    .stat-box.red .val { color: #9b2c2c; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th { background: #2d3748; color: white; padding: 4px 6px; text-align: left; font-size: 8px; }
    td { padding: 3px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f7fafc; }
    .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; }
    .badge-green { background: #c6f6d5; color: #276749; }
    .badge-orange { background: #feebc8; color: #c05621; }
    .badge-red { background: #fed7d7; color: #9b2c2c; }
    .badge-gray { background: #e2e8f0; color: #4a5568; }
    .badge-blue { background: #bee3f8; color: #2c5282; }
    .page-break { page-break-before: always; }
    .supplier-block { margin-bottom: 16px; border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden; }
    .supplier-header { background: #2d3748; color: white; padding: 5px 8px; font-size: 10px; font-weight: bold; }
    .supplier-body { padding: 6px; }
    .disc-table th { background: #742a2a; }
    .no-data { color: #a0aec0; font-style: italic; font-size: 8px; padding: 6px; }
    .footer { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 7px; color: #a0aec0; text-align: center; }
    .summary-row td { font-weight: bold; background: #ebf4ff !important; }
    .rec-ok td { background: #f0fff4 !important; }
    .rec-diff td { background: #fffbeb !important; }
    .rec-miss td { background: #fff5f5 !important; }
    .rec-extra td { background: #ebf8ff !important; }
    .badge-teal { background: #b2f5ea; color: #234e52; }
    .ai-block { margin-bottom: 10px; border: 1px solid #bee3f8; border-radius: 4px; overflow: hidden; }
    .ai-header { background: #2b6cb0; color: white; padding: 4px 8px; font-size: 9px; font-weight: bold; }
    .ai-body { padding: 6px; }
</style>
</head>
<body>

<div class="header">
    <h1>Raport Parsare Documente Furnizori</h1>
    <p>Generat: {{ now()->format('d.m.Y H:i') }} &nbsp;|&nbsp; Perioadă analizată: 01.01.{{ now()->year }} – {{ now()->format('d.m.Y') }}</p>
</div>

{{-- ══ STATISTICI GENERALE ══ --}}
<h2>Sumar General</h2>
<div class="stats-grid">
    <div class="stat-box">
        <div class="val">{{ $stats['total_emails'] }}</div>
        <div class="lbl">Emailuri procesate</div>
    </div>
    <div class="stat-box">
        <div class="val">{{ $stats['total_docs'] }}</div>
        <div class="lbl">Documente parsate</div>
    </div>
    <div class="stat-box green">
        <div class="val">{{ $stats['matched'] }}</div>
        <div class="lbl">Matched WinMentor</div>
    </div>
    <div class="stat-box orange">
        <div class="val">{{ $stats['partial'] }}</div>
        <div class="lbl">Match parțial</div>
    </div>
    <div class="stat-box red">
        <div class="val">{{ $stats['unmatched'] }}</div>
        <div class="lbl">Nematch-uite</div>
    </div>
    <div class="stat-box gray">
        <div class="val">{{ $stats['errors'] }}</div>
        <div class="lbl">Erori parsare</div>
    </div>
</div>

{{-- ══ SUMAR PE FURNIZORI ══ --}}
<h2>Sumar per Furnizor</h2>
<table>
    <thead>
        <tr>
            <th>Furnizor</th>
            <th>Documente</th>
            <th>Avize</th>
            <th>Facturi</th>
            <th>Alte</th>
            <th>Matched</th>
            <th>Parțial</th>
            <th>Nematch.</th>
            <th>Erori</th>
            <th>Discrepanțe</th>
        </tr>
    </thead>
    <tbody>
        @foreach($supplierStats as $row)
        <tr>
            <td>{{ $row['name'] }}</td>
            <td><strong>{{ $row['total'] }}</strong></td>
            <td>{{ $row['aviz'] }}</td>
            <td>{{ $row['factura'] }}</td>
            <td>{{ $row['other'] }}</td>
            <td><span class="badge badge-green">{{ $row['matched'] }}</span></td>
            <td><span class="badge badge-orange">{{ $row['partial'] }}</span></td>
            <td><span class="badge badge-red">{{ $row['unmatched'] }}</span></td>
            <td><span class="badge badge-gray">{{ $row['errors'] }}</span></td>
            <td>{{ $row['discrepancy_count'] > 0 ? $row['discrepancy_count'] : '–' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- ══ DOCUMENTE FĂRĂ INTRARE WM ══ --}}
@if($unmatchedDocs->isNotEmpty())
<h2>Documente Fără Intrare în WinMentor</h2>
<p style="font-size:8px;color:#718096;margin-bottom:6px;">Aceste documente nu au putut fi asociate cu nicio intrare din WinMentor — candidați pentru PO viitoare.</p>
<table>
    <thead>
        <tr>
            <th>Furnizor</th>
            <th>Tip</th>
            <th>Nr. Document</th>
            <th>Data</th>
            <th>Fișier</th>
            <th>Produse</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($unmatchedDocs as $doc)
        <tr>
            <td>{{ $doc->supplier?->name ?? '—' }}</td>
            <td><span class="badge badge-blue">{{ strtoupper($doc->doc_type) }}</span></td>
            <td>{{ $doc->doc_number ?? '—' }}</td>
            <td>{{ $doc->doc_date?->format('d.m.Y') ?? '—' }}</td>
            <td style="font-size:7px;color:#4a5568;">{{ $doc->attachment_name ?? 'body email' }}</td>
            <td>{{ count($doc->products ?? []) }}</td>
            <td><span class="badge badge-red">{{ $doc->match_status }}</span></td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══ DISCREPANȚE SKU ══ --}}
@if($allDiscrepancies->isNotEmpty())
<div class="page-break"></div>
<h2>Discrepanțe SKU / Cantități</h2>
<p style="font-size:8px;color:#718096;margin-bottom:6px;">Produse găsite în documente furnizori unde SKU-ul sau cantitatea diferă față de înregistrarea din WinMentor.</p>

@foreach($discrepanciesBySupplier as $supplierName => $docs)
<div class="supplier-block">
    <div class="supplier-header">{{ $supplierName }}</div>
    <div class="supplier-body">
        @foreach($docs as $docInfo)
        <h3>{{ strtoupper($docInfo['doc_type']) }} {{ $docInfo['doc_number'] ?? '—' }} &nbsp;|&nbsp; {{ $docInfo['doc_date'] ?? '—' }} &nbsp;|&nbsp; WM: {{ $docInfo['wm_doc'] ?? '—' }}</h3>
        <table class="disc-table">
            <thead>
                <tr>
                    <th>Tip</th>
                    <th>SKU Furnizor</th>
                    <th>Denumire Furnizor</th>
                    <th>SKU ERP</th>
                    <th>Denumire ERP</th>
                    <th>Cant. Furnizor</th>
                    <th>Cant. WM</th>
                    <th>Preț Furnizor</th>
                    <th>Preț Achiz. ERP</th>
                </tr>
            </thead>
            <tbody>
                @foreach($docInfo['discrepancies'] as $disc)
                <tr>
                    <td>
                        @if($disc['tip'] === 'sku_negasit_in_wm')
                            <span class="badge badge-red">SKU lipsă</span>
                        @else
                            <span class="badge badge-orange">Cant. diff</span>
                        @endif
                    </td>
                    <td style="font-family:monospace;font-size:8px;">{{ $disc['sku_furnizor'] ?? '—' }}</td>
                    <td>{{ $disc['den_furnizor'] ?? '—' }}</td>
                    <td style="font-family:monospace;font-size:8px;">{{ $disc['sku_nostru'] ?? '—' }}</td>
                    <td>{{ $disc['den_nostru'] ?? '—' }}</td>
                    <td>{{ $disc['cant_furnizor'] ?? '—' }}</td>
                    <td>{{ $disc['cant_nostru'] ?? '—' }}</td>
                    <td>
                        @if(!is_null($disc['pret_furnizor']))
                            {{ number_format((float)$disc['pret_furnizor'], 2) }} {{ $disc['moneda'] ?? '' }}
                        @else —
                        @endif
                    </td>
                    <td>
                        @if(!is_null($disc['pret_nostru']))
                            {{ number_format((float)$disc['pret_nostru'], 2) }} RON
                        @else —
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endforeach
    </div>
</div>
@endforeach
@endif

{{-- ══ COMPARAȚIE AVIZ vs RECEPȚIE WINMENTOR ══ --}}
@if(!empty($receptiiComparison))
<div class="page-break"></div>
<h2>Comparație Aviz vs Recepție WinMentor</h2>
<p style="font-size:8px;color:#718096;margin-bottom:6px;">
    Fiecare produs din aviz comparat cu ce a fost efectiv recepționat în WinMentor.
    <span class="badge" style="background:#c6f6d5;color:#276749;">OK</span> = potrivire exactă &nbsp;
    <span class="badge badge-orange">Cant. diff</span> = cantitate diferită &nbsp;
    <span class="badge badge-red">Lipsă WM</span> = în aviz dar nu în WinMentor &nbsp;
    <span class="badge badge-blue">Extra WM</span> = în WinMentor dar nu în aviz
</p>

@foreach($receptiiComparison as $supplierName => $supplierDocs)
<div class="supplier-block">
    <div class="supplier-header">{{ $supplierName }}</div>
    <div class="supplier-body">
        @foreach($supplierDocs as $docInfo)
        <h3>
            {{ strtoupper($docInfo['doc_type']) }} {{ $docInfo['doc_number'] ?? '—' }}
            &nbsp;|&nbsp; {{ $docInfo['doc_date'] ?? '—' }}
            &nbsp;|&nbsp; WM: {{ $docInfo['wm_doc'] ?? '—' }}
            &nbsp;
            @if($docInfo['match_status'] === 'matched')
                <span class="badge badge-green">matched</span>
            @else
                <span class="badge badge-orange">partial</span>
            @endif
        </h3>
        <table>
            <thead>
                <tr>
                    <th>SKU Furnizor</th>
                    <th>Denumire Aviz</th>
                    <th>Cant. Aviz</th>
                    <th>SKU WinMentor</th>
                    <th>Denumire WM</th>
                    <th>Cant. WM</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($docInfo['rows'] as $row)
                @php
                    $trClass = match($row['status']) {
                        'ok'               => 'rec-ok',
                        'cantitate_diferita' => 'rec-diff',
                        'lipsa_wm'         => 'rec-miss',
                        'intrare_fara_aviz' => 'rec-extra',
                        default            => '',
                    };
                @endphp
                <tr class="{{ $trClass }}">
                    <td style="font-family:monospace;font-size:8px;">{{ $row['sku_furnizor'] ?? '—' }}</td>
                    <td>{{ $row['den_furnizor'] ?? '—' }}</td>
                    <td>{{ $row['cant_doc'] ?? '—' }}</td>
                    <td style="font-family:monospace;font-size:8px;">{{ $row['sku_wm'] ?? '—' }}</td>
                    <td>{{ $row['den_wm'] ?? '—' }}</td>
                    <td>{{ $row['cant_wm'] ?? '—' }}</td>
                    <td>
                        @if($row['status'] === 'ok')
                            <span class="badge badge-green">OK</span>
                        @elseif($row['status'] === 'cantitate_diferita')
                            <span class="badge badge-orange">Cant. diff</span>
                        @elseif($row['status'] === 'lipsa_wm')
                            <span class="badge badge-red">Lipsă WM</span>
                        @else
                            <span class="badge badge-blue">Extra WM</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endforeach
    </div>
</div>
@endforeach
@endif

{{-- ══ SUGESTII AI MAPARE SKU ══ --}}
@if(!empty($aiSuggestions))
<div class="page-break"></div>
<h2>Sugestii Mapare SKU — AI (de verificat manual)</h2>
<p style="font-size:8px;color:#718096;margin-bottom:8px;">
    Produse din documentele nematch-uite pentru care AI-ul a găsit potențiale corespondențe în ERP pe baza denumirii.
    <strong>Acestea sunt DOAR sugestii</strong> — nu au modificat statusul documentelor și nu au fost salvate în sistem.
    Verificați și confirmați manual mapările corecte.
</p>

@foreach($aiSuggestions as $supplierName => $suggestions)
<div class="ai-block">
    <div class="ai-header">{{ $supplierName }} &nbsp;({{ count($suggestions) }} sugestii)</div>
    <div class="ai-body">
        <table>
            <thead>
                <tr>
                    <th>SKU Furnizor</th>
                    <th>Denumire Furnizor</th>
                    <th>SKU ERP</th>
                    <th>Denumire ERP</th>
                    <th>Confidence AI</th>
                </tr>
            </thead>
            <tbody>
                @foreach($suggestions as $sug)
                <tr>
                    <td style="font-family:monospace;font-size:8px;">{{ $sug['sku_furnizor'] ?? '—' }}</td>
                    <td>{{ $sug['den_furnizor'] ?? '—' }}</td>
                    <td style="font-family:monospace;font-size:8px;">{{ $sug['sku_erp'] ?? '—' }}</td>
                    <td>{{ $sug['den_erp'] ?? '—' }}</td>
                    <td>
                        @php $conf = (float)($sug['confidence'] ?? 0); @endphp
                        @if($conf >= 0.85)
                            <span class="badge badge-green">{{ number_format($conf * 100, 0) }}%</span>
                        @elseif($conf >= 0.7)
                            <span class="badge badge-orange">{{ number_format($conf * 100, 0) }}%</span>
                        @else
                            <span class="badge badge-gray">{{ number_format($conf * 100, 0) }}%</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach
@endif

{{-- ══ ERORI PARSARE ══ --}}
@if($errorDocs->isNotEmpty())
<h2>Documente cu Erori de Parsare</h2>
<table>
    <thead>
        <tr>
            <th>Furnizor</th>
            <th>Fișier</th>
            <th>Data email</th>
            <th>Eroare</th>
        </tr>
    </thead>
    <tbody>
        @foreach($errorDocs as $doc)
        <tr>
            <td>{{ $doc->supplier?->name ?? '—' }}</td>
            <td style="font-size:7px;">{{ $doc->attachment_name ?? 'body email' }}</td>
            <td>{{ $doc->emailMessage?->sent_at?->format('d.m.Y') ?? '—' }}</td>
            <td style="font-size:7px;color:#9b2c2c;">{{ $doc->error_message }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<div class="footer">
    Malinco ERP &nbsp;|&nbsp; Raport generat automat &nbsp;|&nbsp; {{ now()->format('d.m.Y H:i:s') }}
    &nbsp;|&nbsp; Total documente salvate în DB: {{ $stats['total_docs'] }}
</div>

</body>
</html>
