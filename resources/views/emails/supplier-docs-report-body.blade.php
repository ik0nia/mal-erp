<!DOCTYPE html>
<html lang="ro">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#1a1a1a;max-width:600px;margin:0 auto;padding:20px;">
    <h2 style="color:#1e3a5f;border-bottom:2px solid #1e3a5f;padding-bottom:8px;">
        Raport Documente Furnizori — {{ now()->format('d.m.Y') }}
    </h2>

    <p>Procesarea documentelor furnizorilor (PDF-uri și XLSX-uri din emailuri) pentru perioada
    <strong>01.01.{{ now()->year }} – {{ now()->format('d.m.Y') }}</strong> a fost finalizată.</p>

    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
        <tr style="background:#2d3748;color:white;">
            <th style="padding:8px 12px;text-align:left;">Indicator</th>
            <th style="padding:8px 12px;text-align:right;">Valoare</th>
        </tr>
        <tr style="background:#f7fafc;">
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;">Emailuri procesate</td>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:bold;">{{ $stats['total_emails'] }}</td>
        </tr>
        <tr>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;">Documente parsate</td>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:bold;">{{ $stats['total_docs'] }}</td>
        </tr>
        <tr style="background:#f7fafc;">
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;">Matched WinMentor</td>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;text-align:right;color:#276749;font-weight:bold;">{{ $stats['matched'] }}</td>
        </tr>
        <tr>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;">Match parțial</td>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;text-align:right;color:#c05621;font-weight:bold;">{{ $stats['partial'] }}</td>
        </tr>
        <tr style="background:#f7fafc;">
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;">Fără intrare WinMentor</td>
            <td style="padding:6px 12px;border-bottom:1px solid #e2e8f0;text-align:right;color:#9b2c2c;font-weight:bold;">{{ $stats['unmatched'] }}</td>
        </tr>
        <tr>
            <td style="padding:6px 12px;">Erori parsare</td>
            <td style="padding:6px 12px;text-align:right;color:#4a5568;font-weight:bold;">{{ $stats['errors'] }}</td>
        </tr>
    </table>

    <p>Raportul complet PDF este atașat acestui email. Conține:</p>
    <ul style="line-height:1.8;">
        <li>Sumar per furnizor</li>
        <li>Documente fără intrare în WinMentor (candidați pentru PO viitoare)</li>
        <li>Discrepanțe SKU cu prețuri comparative</li>
        <li>Lista erorilor de parsare</li>
    </ul>

    <p style="margin-top:16px;padding:12px;background:#fffbeb;border-left:4px solid #f6ad55;font-size:13px;">
        <strong>Notă:</strong> Nu au fost create Purchase Orders automat. Deciziile de aprovizionare rămân la latitudinea ta după analiza raportului.
    </p>

    <p style="margin-top:20px;font-size:12px;color:#718096;">
        Malinco ERP &nbsp;·&nbsp; {{ now()->format('d.m.Y H:i') }}
    </p>
</body>
</html>
