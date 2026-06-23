@extends('app.layout')

@section('title', 'ERP Malinco')

@push('head')
<style>
    .hub-greet{font-size:.95rem;color:#6b7280;margin-bottom:1rem;}
    .hub-greet strong{color:#1f2937;}
    .hub-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;}
    .hub-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;background:#fff;border:1px solid #ece6da;border-radius:1rem;padding:1.5rem 1rem;text-decoration:none;color:#1f2937;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:transform .08s,box-shadow .12s;min-height:9.5rem;}
    .hub-tile:active{transform:scale(.97);box-shadow:0 1px 2px rgba(0,0,0,.04);}
    .hub-tile-icon{width:3.75rem;height:3.75rem;border-radius:1rem;display:flex;align-items:center;justify-content:center;font-size:1.9rem;}
    .hub-tile-label{font-size:.95rem;font-weight:700;text-align:center;}
    .hub-tile-desc{font-size:.72rem;color:#9ca3af;text-align:center;line-height:1.25;}
    .hub-empty{text-align:center;color:#9ca3af;padding:3rem 1rem;}
</style>
@endpush

@section('content')
    <div class="hub-greet">Salut, <strong>{{ $user?->name }}</strong> 👋 — ce vrei să faci?</div>

    @if($tiles->isEmpty())
        <div class="hub-empty">Nu ai nicio funcție disponibilă încă.</div>
    @else
        <div class="hub-grid">
            @foreach($tiles as $tile)
                <a href="{{ $tile['url'] }}" class="hub-tile">
                    <span class="hub-tile-icon" style="background:{{ $tile['color'] }}1a;">{{ $tile['icon'] }}</span>
                    <span class="hub-tile-label">{{ $tile['label'] }}</span>
                    <span class="hub-tile-desc">{{ $tile['desc'] }}</span>
                </a>
            @endforeach
        </div>
    @endif
@endsection
