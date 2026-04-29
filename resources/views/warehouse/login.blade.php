@extends('warehouse.layout')
@section('title', 'Login — Recepție')

@section('body')
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">
            <img src="/malinco-logo.png" alt="Malinco" style="height:56px; object-fit:contain; margin-bottom:12px">
            <p style="font-size:13px; color:#64748b">Recepție Marfă — autentifică-te pentru a continua</p>
        </div>

        @if ($errors->any())
            <div class="banner banner-error" style="margin-bottom:16px">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('warehouse.login.post') }}">
            @csrf
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-input" value="{{ old('email') }}"
                    autocomplete="email" placeholder="email@malinco.ro" autofocus>
            </div>
            <div class="form-group">
                <label>Parolă</label>
                <input type="password" name="password" class="form-input"
                    autocomplete="current-password" placeholder="••••••••">
            </div>
<button type="submit" class="btn btn-primary" style="margin-top:8px">
                Intră în aplicație
            </button>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // Remember email in localStorage
    const emailInput = document.querySelector('input[name=email]');
    const savedEmail = localStorage.getItem('wh_email');
    if (savedEmail && !emailInput.value) emailInput.value = savedEmail;

    document.querySelector('form').addEventListener('submit', () => {
        localStorage.setItem('wh_email', emailInput.value);
    });
</script>
@endsection
