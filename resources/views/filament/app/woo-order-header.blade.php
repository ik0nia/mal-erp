@php
    /** @var \App\Models\WooOrder $record */
    $record   = $getRecord();
    $editable = $this->isOrderEditable();
    $b = (array) ($record->billing ?? []);
    $s = (array) ($record->shipping ?? []);

    $fmtAddr = function (array $a): array {
        $lines = array_filter([
            trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
            $a['company'] ?? '',
            $a['address_1'] ?? '',
            $a['address_2'] ?? '',
            trim(($a['postcode'] ?? '').' '.($a['city'] ?? '')).(!empty($a['state']) ? ', '.$a['state'] : ''),
        ], fn ($l) => trim((string) $l) !== '' && trim((string) $l) !== ',');
        return $lines;
    };
    $bLines = $fmtAddr($b);
    $meaningful = array_filter(array_diff_key($s, ['first_name' => 1, 'last_name' => 1]));
    $sLines = empty(array_filter($meaningful)) ? null : $fmtAddr($s);
    $shipLine = collect($record->data['shipping_lines'] ?? [])->first();

    $money = fn ($v) => number_format((float) $v, 2, ',', '.') . ' lei';

    // Fișa clientului 360° — link dacă găsim clientul după email sau telefon
    $custEmail = trim((string) ($b['email'] ?? ''));
    $custPhone = preg_replace('/\D+/', '', (string) ($b['phone'] ?? ''));
    $customer = null;
    if ($custEmail !== '' || $custPhone !== '') {
        $customer = \App\Models\Customer::query()
            ->when($custEmail !== '', fn ($q) => $q->orWhere('email', $custEmail))
            ->when(strlen($custPhone) >= 6, fn ($q) => $q->orWhereRaw("REPLACE(REPLACE(phone,' ',''),'-','') LIKE ?", ["%{$custPhone}%"]))
            ->first();
    }
    $customerUrl = $customer ? \App\Filament\App\Resources\CustomerResource::getUrl('view', ['record' => $customer]) : null;
@endphp

<style>
.oh-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;}
@media (max-width:900px){.oh-grid{grid-template-columns:1fr;}}
.oh-col h4{display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin:0 0 .5rem;}
.oh-col p{margin:.15rem 0;font-size:.875rem;color:#374151;}
.oh-col .oh-strong{font-weight:600;color:#111827;}
.oh-pencil{border:none;background:transparent;cursor:pointer;color:#9ca3af;font-size:.9rem;padding:.15rem .35rem;border-radius:.375rem;line-height:1;}
.oh-pencil:hover{background:#eef2ff;color:#4f46e5;}
.oh-muted{color:#9ca3af;font-size:.8rem;}
.oh-note{margin-top:.5rem;padding:.5rem .75rem;background:#fffbeb;border:1px solid #fef3c7;border-radius:.5rem;font-size:.83rem;color:#92400e;font-style:italic;}
</style>

<div class="oh-grid">
  {{-- GENERAL --}}
  <div class="oh-col">
    <h4>General
      @if($record->woo_id)
        <button type="button" class="oh-pencil" title="Schimbă statusul" wire:click="mountAction('changeStatus')">✎</button>
      @endif
    </h4>
    <p><span class="oh-strong">#{{ $record->number }}</span> · {{ $record->order_date?->format('d.m.Y H:i') }}</p>
    <p>
      <span style="display:inline-block;padding:.15rem .6rem;border-radius:.4rem;font-size:.78rem;font-weight:700;background:{{ ['pending'=>'#fef3c7','processing'=>'#dbeafe','on-hold'=>'#fde68a','completed'=>'#dcfce7','cancelled'=>'#fee2e2','refunded'=>'#f3f4f6','failed'=>'#fee2e2'][$record->status] ?? '#f3f4f6' }};color:{{ ['pending'=>'#b45309','processing'=>'#1d4ed8','on-hold'=>'#92400e','completed'=>'#15803d','cancelled'=>'#b91c1c','refunded'=>'#6b7280','failed'=>'#b91c1c'][$record->status] ?? '#374151' }};">
        {{ \App\Models\WooOrder::STATUS_LABELS[$record->status] ?? $record->status }}
      </span>
    </p>
    <p>Plată: <span class="oh-strong">{{ $record->payment_method_title ?: '—' }}</span></p>
    @if($record->date_paid)<p class="oh-muted">Plătită: {{ \Carbon\Carbon::parse($record->date_paid)->format('d.m.Y H:i') }}</p>@endif
  </div>

  {{-- FACTURARE --}}
  <div class="oh-col">
    <h4>Facturare
      @if($editable)
        <button type="button" class="oh-pencil" title="Editează datele de facturare" wire:click="mountAction('editBilling')">✎</button>
      @endif
    </h4>
    @forelse($bLines as $i => $line)
      <p class="{{ $i === 0 ? 'oh-strong' : '' }}">{{ $line }}</p>
    @empty
      <p class="oh-muted">—</p>
    @endforelse
    @if(!empty($b['email']))<p class="oh-muted">✉ {{ $b['email'] }}</p>@endif
    @if(!empty($b['phone']))<p class="oh-muted">☎ {{ $b['phone'] }}</p>@endif
    @if($customerUrl)
      <p style="margin-top:.35rem;"><a href="{{ $customerUrl }}" style="font-size:.8rem;font-weight:600;color:#4f46e5;text-decoration:none;">👤 Fișă client 360° →</a></p>
    @endif
  </div>

  {{-- LIVRARE --}}
  <div class="oh-col">
    <h4>Livrare
      @if($editable)
        <button type="button" class="oh-pencil" title="Editează adresa de livrare" wire:click="mountAction('editShipping')">✎</button>
      @endif
    </h4>
    @if($sLines)
      @foreach($sLines as $i => $line)
        <p class="{{ $i === 0 ? 'oh-strong' : '' }}">{{ $line }}</p>
      @endforeach
      @if(!empty($s['phone']))<p class="oh-muted">☎ {{ $s['phone'] }}</p>@endif
    @else
      <p class="oh-muted">La fel ca facturarea</p>
    @endif
    @php $locker = $this->currentLocker(); @endphp
    @if($locker)
      <p style="margin-top:.4rem;">
        <span style="display:inline-block;padding:.15rem .55rem;border-radius:.4rem;font-size:.75rem;font-weight:700;background:#ede9fe;color:#6d28d9;">📦 EASYBOX</span>
        <span style="font-size:.83rem;color:#374151;"> {{ $locker['name'] ?? '' }} — {{ $locker['address'] ?? '' }}, {{ $locker['city'] ?? '' }}</span>
        @if($editable)
          <button type="button" class="oh-pencil" title="Schimbă căsuța Easybox / metoda" wire:click="mountAction('editTransport')">✎</button>
        @endif
      </p>
    @endif
    @if($shipLine)
      <p class="oh-muted" style="margin-top:.4rem;">🚚 {{ $shipLine['method_title'] ?? '' }} — {{ number_format((float) $record->shipping_total * 1.21, 2) }} lei</p>
    @endif
    @if($record->customer_note)
      <div class="oh-note">„{{ $record->customer_note }}"</div>
    @endif
  </div>
</div>

{{-- SUMAR FINANCIAR --}}
@php
    $disc = (float) ($record->discount_total ?? 0);
    $ship = (float) ($record->shipping_total ?? 0);
    $tax  = (float) ($record->tax_total ?? 0);
    $fee  = (float) ($record->fee_total ?? 0);
@endphp
<div class="oh-totals">
  <div class="oh-totals-row"><span>Subtotal produse</span><span>{{ $money($record->subtotal) }}</span></div>
  @if($ship != 0)<div class="oh-totals-row"><span>Transport</span><span>{{ $money($ship) }}</span></div>@endif
  @if($fee != 0)<div class="oh-totals-row"><span>Taxe suplimentare</span><span>{{ $money($fee) }}</span></div>@endif
  @if($disc != 0)<div class="oh-totals-row" style="color:#b91c1c;"><span>Discount</span><span>−{{ $money($disc) }}</span></div>@endif
  @if($tax != 0)<div class="oh-totals-row oh-muted"><span>din care TVA</span><span>{{ $money($tax) }}</span></div>@endif
  <div class="oh-totals-row oh-totals-final"><span>TOTAL</span><span>{{ $money($record->total) }} {{ $record->currency }}</span></div>
</div>
<style>
.oh-totals{margin-top:1rem;margin-left:auto;max-width:340px;border:1px solid #f3f4f6;border-radius:.6rem;padding:.5rem .9rem;background:#fafafa;}
.oh-totals-row{display:flex;justify-content:space-between;gap:1rem;font-size:.86rem;color:#374151;padding:.2rem 0;}
.oh-totals-final{border-top:1px solid #e5e7eb;margin-top:.25rem;padding-top:.5rem;font-size:1.05rem;font-weight:800;color:#111827;}
</style>
