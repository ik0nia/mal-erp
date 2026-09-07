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
        <button type="button" class="oh-pencil" title="Editează datele clientului" wire:click="mountAction('editAddress')">✎</button>
      @endif
    </h4>
    @forelse($bLines as $i => $line)
      <p class="{{ $i === 0 ? 'oh-strong' : '' }}">{{ $line }}</p>
    @empty
      <p class="oh-muted">—</p>
    @endforelse
    @if(!empty($b['email']))<p class="oh-muted">✉ {{ $b['email'] }}</p>@endif
    @if(!empty($b['phone']))<p class="oh-muted">☎ {{ $b['phone'] }}</p>@endif
  </div>

  {{-- LIVRARE --}}
  <div class="oh-col">
    <h4>Livrare
      @if($editable)
        <button type="button" class="oh-pencil" title="Editează adresa de livrare și transportul" wire:click="mountAction('editAddress')">✎</button>
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
    @if($shipLine)
      <p class="oh-muted" style="margin-top:.4rem;">🚚 {{ $shipLine['method_title'] ?? '' }} — {{ number_format((float) $record->shipping_total * 1.21, 2) }} lei</p>
    @endif
    @if($record->customer_note)
      <div class="oh-note">„{{ $record->customer_note }}"</div>
    @endif
  </div>
</div>
