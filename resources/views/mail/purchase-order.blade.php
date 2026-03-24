<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.6; }
  .container { max-width: 640px; margin: 0 auto; padding: 24px; }
  .header { border-bottom: 2px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 20px; }
  .po-number { font-size: 12px; color: #6b7280; }
  .body { white-space: pre-line; }
  .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; }
</style>
</head>
<body>
  <div class="container">
    <div class="header">
      <span class="po-number">{{ $order->number }}</span>
    </div>
    <div class="body">{{ $emailBody }}</div>
    <div class="footer">
      Acest email a fost generat automat din sistemul ERP Malinco.
    </div>
  </div>
</body>
</html>
