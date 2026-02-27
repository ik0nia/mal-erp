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

<div class="hidden md:flex flex-col items-end justify-center leading-tight mr-1 erp-user-info">
    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $user->name }}</span>
    @if($roleLabel)
        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $roleLabel }}</span>
    @endif
</div>
