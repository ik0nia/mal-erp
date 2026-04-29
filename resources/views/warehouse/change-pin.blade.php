@extends('warehouse.layout')
@section('title', 'Schimbă PIN')

@section('body')
<div class="wh-header">
    <a href="{{ route('warehouse.orders') }}" class="back-btn">‹</a>
    <img src="/malinco-logo.png" alt="Malinco" class="header-logo">
    <div style="width:48px"></div>
</div>

<div class="wh-content">
    <div class="wh-card">
        <div style="font-weight:700; font-size:17px; margin-bottom:4px">Schimbă PIN</div>
        <div style="font-size:13px; color:#64748b; margin-bottom:20px">PIN-ul are 4 cifre și se folosește la autentificare.</div>

        <div id="msg-error" class="banner banner-error" style="display:none; margin-bottom:16px"></div>
        <div id="msg-success" class="banner banner-success" style="display:none; margin-bottom:16px"></div>

        <form id="pin-form">
            @csrf
            <div class="form-group">
                <label>PIN curent</label>
                <input type="password" name="current_pin" class="form-input"
                    inputmode="numeric" pattern="\d{4}" maxlength="4"
                    autocomplete="off" placeholder="••••"
                    style="letter-spacing:8px; font-size:22px; text-align:center">
            </div>
            <div class="form-group">
                <label>PIN nou</label>
                <input type="password" name="new_pin" class="form-input"
                    inputmode="numeric" pattern="\d{4}" maxlength="4"
                    autocomplete="off" placeholder="••••"
                    style="letter-spacing:8px; font-size:22px; text-align:center">
            </div>
            <div class="form-group">
                <label>Confirmă PIN nou</label>
                <input type="password" name="confirm_pin" class="form-input"
                    inputmode="numeric" pattern="\d{4}" maxlength="4"
                    autocomplete="off" placeholder="••••"
                    style="letter-spacing:8px; font-size:22px; text-align:center">
            </div>
            <button type="submit" id="submit-btn" class="btn btn-primary" style="margin-top:8px">
                Salvează PIN-ul nou
            </button>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.getElementById('pin-form').addEventListener('submit', async function(e) {
        e.preventDefault();

        const btn = document.getElementById('submit-btn');
        const errEl = document.getElementById('msg-error');
        const okEl  = document.getElementById('msg-success');
        errEl.style.display = 'none';
        okEl.style.display  = 'none';

        const data = {
            current_pin: this.current_pin.value,
            new_pin:     this.new_pin.value,
            confirm_pin: this.confirm_pin.value,
        };

        if (data.new_pin !== data.confirm_pin) {
            errEl.textContent = 'PIN-urile noi nu coincid.';
            errEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Se salvează...';

        try {
            const res = await fetch('{{ route("warehouse.pin.change.post") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify(data),
            });
            const json = await res.json();

            if (json.ok) {
                okEl.textContent = json.message;
                okEl.style.display = 'block';
                this.reset();
                setTimeout(() => { window.location.href = '/wh/'; }, 1500);
            } else {
                errEl.textContent = json.error || 'Eroare la salvare.';
                errEl.style.display = 'block';
            }
        } catch(e) {
            errEl.textContent = 'Eroare de rețea.';
            errEl.style.display = 'block';
        }

        btn.disabled = false;
        btn.innerHTML = 'Salvează PIN-ul nou';
    });
</script>
@endsection
