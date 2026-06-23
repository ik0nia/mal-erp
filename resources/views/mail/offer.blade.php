<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.6; }
  .container { max-width: 640px; margin: 0 auto; padding: 24px; }
  .brand { height: 5px; background: #c01722; border-radius: 3px; }
  .header { padding: 14px 0 12px; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; }
  .offer-number { font-size: 12px; color: #6b7280; }
  .offer-title { font-size: 18px; font-weight: bold; color: #111827; }
  .body { white-space: pre-line; }
  .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; }
</style>
</head>
<body>
  <div class="container">
    <div class="brand"></div>
    <div class="header">
      <div class="offer-title">Ofertă comercială</div>
      <span class="offer-number">{{ $offer->number }} · {{ $offer->created_at?->format('d.m.Y') }}</span>
    </div>
    <div class="body">{{ $emailBody }}</div>
    <div class="footer">
      Oferta detaliată este atașată acestui email în format PDF.<br>
      {{ $offer->location?->company_name ?: 'Malinco Prodex S.R.L.' }}
    </div>
  </div>
</body>
</html>
