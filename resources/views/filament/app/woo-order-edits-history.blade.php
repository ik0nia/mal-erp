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
@endphp

<div style="display:flex;flex-direction:column;gap:.4rem;">
  @foreach($edits as $edit)
    @php [$tip, $fg, $bg] = $labels[$edit->action] ?? [$edit->action, '#374151', '#f3f4f6']; @endphp
    <div style="display:flex;align-items:center;gap:.75rem;padding:.5rem .75rem;border:1px solid #f3f4f6;border-radius:.5rem;{{ $edit->reverted_at ? 'opacity:.55;' : '' }}">
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
  @endforeach
</div>
