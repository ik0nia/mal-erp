<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $subject ?? 'Malinco ERP' }}</title>
</head>
<body style="margin:0;padding:0;background:#F5F0E8;font-family:Arial,Helvetica,sans-serif;color:#1A1A1A;">

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F5F0E8;min-height:100vh;">
    <tr>
      <td align="center" style="padding:16px;">

        <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">

          {{-- Header --}}
          <tr>
            <td style="background:#B91C1C;padding:28px 32px;text-align:center;">
              <img src="https://erp.malinco.ro/malinco-logo-white.png"
                   alt="Malinco"
                   width="140"
                   style="display:inline-block;height:auto;">
              @if(!empty($headerTitle))
              <p style="margin:14px 0 0;color:rgba(255,255,255,0.80);font-size:12px;letter-spacing:1px;text-transform:uppercase;">
                {{ $headerTitle }}
              </p>
              @endif
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="background:#FFFFFF;padding:20px 32px 32px;">
              @yield('content')
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="background:#F5F0E8;padding:20px 32px;border-top:1px solid #E8DFD0;text-align:center;">
              <p style="margin:0;font-size:11px;color:#9CA3AF;line-height:1.8;">
                Generat automat de sistemul ERP Malinco &mdash; {{ now()->setTimezone('Europe/Bucharest')->format('d.m.Y H:i') }}<br>
                Nu răspunde la acest email.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
