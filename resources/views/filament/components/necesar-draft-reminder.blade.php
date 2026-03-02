@php
use App\Models\PurchaseRequest;
use App\Filament\App\Resources\PurchaseRequestResource;

$user = auth()->user();
if (! $user || ! $user instanceof \App\Models\User) return;

$draft = PurchaseRequest::query()
    ->where('user_id', $user->id)
    ->where('status', PurchaseRequest::STATUS_DRAFT)
    ->withCount('items')
    ->first();

if (! $draft || $draft->items_count === 0) return;

$count = $draft->items_count;
$url   = PurchaseRequestResource::getUrl('edit', ['record' => $draft->id]);
@endphp

<div style="background:#fef2f2;border-bottom:2px solid #fecaca;padding:8px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:8px;color:#991b1b;font-size:0.875rem;font-weight:500;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
        </svg>
        Ai un necesar deschis cu <strong style="margin:0 3px;">{{ $count }} {{ $count === 1 ? 'produs' : 'produse' }}</strong> netrimis.
    </div>
    <a href="{{ $url }}" style="background:#dc2626;color:#fff;border-radius:6px;padding:5px 14px;font-size:0.8rem;font-weight:700;text-decoration:none;white-space:nowrap;">
        Deschide coșul →
    </a>
</div>
