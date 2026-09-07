@props(['paginator', 'pageName'])

@if($paginator->hasPages())
    <div style="display:flex; align-items:center; justify-content:space-between; margin-top:0.6rem; font-size:0.8rem; color:#6b7280;">
        <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} din {{ $paginator->total() }}</span>
        <div style="display:flex; gap:0.4rem; align-items:center;">
            <button wire:click="previousPage('{{ $pageName }}')" @disabled($paginator->onFirstPage())
                style="padding:0.3rem 0.8rem; border:1px solid #e5e7eb; border-radius:0.4rem; background:#fff; cursor:{{ $paginator->onFirstPage() ? 'default' : 'pointer' }}; color:{{ $paginator->onFirstPage() ? '#d1d5db' : '#374151' }};">
                ← Înapoi
            </button>
            <span style="padding:0 0.3rem;">pag. {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <button wire:click="nextPage('{{ $pageName }}')" @disabled(! $paginator->hasMorePages())
                style="padding:0.3rem 0.8rem; border:1px solid #e5e7eb; border-radius:0.4rem; background:#fff; cursor:{{ $paginator->hasMorePages() ? 'pointer' : 'default' }}; color:{{ $paginator->hasMorePages() ? '#374151' : '#d1d5db' }};">
                Înainte →
            </button>
        </div>
    </div>
@endif
