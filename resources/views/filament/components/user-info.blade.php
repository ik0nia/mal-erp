@php
    $user = auth()->user();
    if (! $user) return;

    if ($user->is_super_admin) {
        $roleLabel = 'Super Admin';
    } elseif ($user->role) {
        $roleLabel = \App\Models\User::roleOptions()[$user->role] ?? ucfirst($user->role);
    } else {
        $roleLabel = null;
    }
@endphp

<div class="erp-user-info" style="display:none;flex-direction:column;align-items:flex-end;justify-content:center;line-height:1.2;margin-right:8px;text-align:right">
    <span style="font-size:14px;font-weight:600;color:#374151;white-space:nowrap">{{ $user->name }}</span>
    @if($roleLabel)
        <span style="font-size:12px;color:#9ca3af;white-space:nowrap">{{ $roleLabel }}</span>
    @endif
</div>
<style>.erp-user-info { display: none !important; } @media (min-width: 768px) { .erp-user-info { display: flex !important; } }</style>
