@php
    /** @var \App\Models\WooOrder $record */
    $record = $getRecord();
    $edits  = $record->edits()->limit(30)->get();
    $labels = [
        'remove_item'  => ['Ștergere produs', '#b91c1c', '#fee2e2'],
        'add_item'     => ['Adăugare produs', '#15803d', '#dcfce7'],
        'edit_items'   => ['Editare produse', '#b45309', '#fef3c7'],
        'edit_address' => ['Livrare/client', '#1d4ed8', '#dbeafe'],
    ];

    $money = fn ($v) => number_format((float) $v, 2, ',', '.') . ' lei';

    // Etichete prietenoase pentru câmpurile de adresă (billing/shipping)
    $fieldLabels = [
        'first_name' => 'Prenume', 'last_name' => 'Nume', 'company' => 'Firmă',
        'address_1' => 'Adresă', 'address_2' => 'Adresă 2', 'city' => 'Oraș',
        'state' => 'Județ', 'postcode' => 'Cod poștal', 'country' => 'Țară',
        'phone' => 'Telefon', 'email' => 'Email',
    ];

    /**
     * Construiește lista de schimbări „vechi → nou" dintr-o pereche before/after.
     * @return array<int, array{what:string, from:?string, to:?string}>
     */
    $diff = function (?array $before, ?array $after) use ($money, $fieldLabels): array {
        $before ??= []; $after ??= [];
        $rows = [];

        // 1. Linii de produs (edit_items) — cheie „lines", grupate pe woo_item_id
        if (isset($before['lines']) || isset($after['lines'])) {
            $b = collect($before['lines'] ?? [])->keyBy('woo_item_id');
            $a = collect($after['lines'] ?? [])->keyBy('woo_item_id');
            foreach ($b->keys()->merge($a->keys())->unique() as $id) {
                $bl = $b->get($id); $al = $a->get($id);
                $name = $al['name'] ?? $bl['name'] ?? ('Produs #' . $id);
                if ($bl && $al) {
                    if ((int) ($bl['quantity'] ?? 0) !== (int) ($al['quantity'] ?? 0)) {
                        $rows[] = ['what' => $name . ' — cantitate', 'from' => (string) ($bl['quantity'] ?? '—'), 'to' => (string) ($al['quantity'] ?? '—')];
                    }
                    $bv = $bl['subtotal'] ?? $bl['total'] ?? null;
                    $av = $al['subtotal'] ?? $al['total'] ?? null;
                    if ($bv !== null && $av !== null && abs((float) $bv - (float) $av) >= 0.01) {
                        $rows[] = ['what' => $name . ' — valoare', 'from' => $money($bv), 'to' => $money($av)];
                    }
                } elseif ($al && ! $bl) {
                    $rows[] = ['what' => $name . ' — adăugat', 'from' => null, 'to' => ($al['quantity'] ?? 1) . ' × ' . $money($al['subtotal'] ?? $al['total'] ?? 0)];
                } elseif ($bl && ! $al) {
                    $rows[] = ['what' => $name . ' — șters', 'from' => ($bl['quantity'] ?? 1) . ' × ' . $money($bl['subtotal'] ?? $bl['total'] ?? 0), 'to' => null];
                }
            }
            return $rows;
        }

        // 2. Transport (edit_address transport) — cheie „shipping_lines"
        if (isset($before['shipping_lines']) || isset($after['shipping_lines'])) {
            $bl = $before['shipping_lines'][0] ?? []; $al = $after['shipping_lines'][0] ?? [];
            if (($bl['method_title'] ?? '') !== ($al['method_title'] ?? '') && ($al['method_title'] ?? '') !== '') {
                $rows[] = ['what' => 'Metodă transport', 'from' => $bl['method_title'] ?? '—', 'to' => $al['method_title'] ?? '—'];
            }
            if (isset($bl['total'], $al['total']) && abs((float) $bl['total'] - (float) $al['total']) >= 0.01) {
                $rows[] = ['what' => 'Cost transport', 'from' => $money($bl['total']), 'to' => $money($al['total'])];
            }
            return $rows;
        }

        // 3. Câmpuri de adresă (billing/shipping) — comparăm cheile modificate
        foreach (['billing', 'shipping'] as $section) {
            if (! isset($before[$section]) && ! isset($after[$section])) {
                continue;
            }
            $bs = (array) ($before[$section] ?? []); $as = (array) ($after[$section] ?? []);
            foreach (array_unique([...array_keys($bs), ...array_keys($as)]) as $k) {
                $from = trim((string) ($bs[$k] ?? '')); $to = trim((string) ($as[$k] ?? ''));
                if ($from !== $to) {
                    $rows[] = ['what' => $fieldLabels[$k] ?? ucfirst($k), 'from' => $from ?: '—', 'to' => $to ?: '—'];
                }
            }
        }
        if ($rows) {
            return $rows;
        }

        // 4. Snapshot simplu (add_item / remove_item fără structură „lines")
        $snap = $after ?: $before;
        if (isset($snap['name'])) {
            $val = ($snap['quantity'] ?? 1) . ' × ' . $money($snap['total'] ?? $snap['subtotal'] ?? $snap['price'] ?? 0);
            $rows[] = $after
                ? ['what' => $snap['name'], 'from' => null, 'to' => $val]
                : ['what' => $snap['name'], 'from' => $val, 'to' => null];
        }
        return $rows;
    };
@endphp

<div style="display:flex;flex-direction:column;gap:.4rem;">
  @foreach($edits as $edit)
    @php
        [$tip, $fg, $bg] = $labels[$edit->action] ?? [$edit->action, '#374151', '#f3f4f6'];
        $changes = $diff($edit->before, $edit->after);
    @endphp
    <div style="display:flex;flex-direction:column;gap:.4rem;padding:.5rem .75rem;border:1px solid #f3f4f6;border-radius:.5rem;{{ $edit->reverted_at ? 'opacity:.55;' : '' }}">
      <div style="display:flex;align-items:center;gap:.75rem;">
        <span style="font-size:.7rem;font-weight:700;padding:.15rem .5rem;border-radius:.375rem;background:{{ $bg }};color:{{ $fg }};white-space:nowrap;">{{ $tip }}</span>
        <span style="flex:1;font-size:.85rem;color:#374151;">
          {{ $edit->label }}
          @if($edit->reverted_at)
            <span style="color:#9ca3af;font-size:.75rem;"> — anulat {{ $edit->reverted_at->format('d.m H:i') }} de {{ $edit->reverted_by }}</span>
          @endif
        </span>
        <span style="font-size:.75rem;color:#9ca3af;white-space:nowrap;">{{ $edit->created_at->format('d.m.Y H:i') }} · {{ $edit->user_email }}</span>
        @if($edit->isRevertible() && $this->isOrderEditable())
          <button wire:click="revertEdit({{ $edit->id }})"
                  wire:confirm="Anulezi modificarea „{{ $edit->label }}"? Comanda revine la starea anterioară în WooCommerce."
                  wire:loading.attr="disabled"
                  style="font-size:.75rem;font-weight:600;color:#4f46e5;border:1px solid #c7d2fe;background:#eef2ff;border-radius:.375rem;padding:.25rem .6rem;cursor:pointer;white-space:nowrap;">
            ↩ Revino
          </button>
        @endif
      </div>

      @if($changes)
        <div style="display:flex;flex-direction:column;gap:.15rem;padding-left:.25rem;border-left:2px solid #e5e7eb;margin-left:.15rem;">
          @foreach($changes as $c)
            <div style="font-size:.78rem;color:#4b5563;display:flex;flex-wrap:wrap;align-items:baseline;gap:.35rem;">
              <span style="color:#6b7280;">{{ $c['what'] }}:</span>
              @if($c['from'] !== null)
                <span style="color:#b91c1c;text-decoration:line-through;">{{ $c['from'] }}</span>
              @endif
              @if($c['from'] !== null && $c['to'] !== null)
                <span style="color:#9ca3af;">→</span>
              @endif
              @if($c['to'] !== null)
                <span style="color:#15803d;font-weight:600;">{{ $c['to'] }}</span>
              @endif
            </div>
          @endforeach
        </div>
      @endif
    </div>
  @endforeach
</div>
