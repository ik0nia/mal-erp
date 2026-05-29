@extends('warehouse.layout')
@section('title', 'PIN — Recepție')

@section('body')
<div style="min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f1f5f9; padding:24px">

    <img src="/malinco-logo.png" alt="Malinco" style="height:48px; object-fit:contain; margin-bottom:32px">

    <div style="background:white; border-radius:24px; padding:32px 24px; width:100%; max-width:340px; box-shadow:0 4px 16px rgba(0,0,0,0.1)">

        <div style="text-align:center; margin-bottom:8px; font-size:15px; font-weight:600; color:#1e293b">
            Bună, {{ Auth::user()->name }}
        </div>
        <div style="text-align:center; margin-bottom:24px; font-size:13px; color:#64748b">
            Introdu PIN-ul pentru a continua
        </div>

        <div id="pin-error" class="banner banner-error" style="display:none; margin-bottom:16px; text-align:center"></div>

        {{-- PIN dots display --}}
        <div id="pin-dots" style="display:flex; justify-content:center; gap:16px; margin-bottom:32px">
            @for($i = 0; $i < 4; $i++)
            <div class="pin-dot" style="width:20px; height:20px; border-radius:50%; border:2px solid #cbd5e1; background:white; transition:background 0.15s, border-color 0.15s"></div>
            @endfor
        </div>

        {{-- Numpad --}}
        <div id="numpad" style="display:grid; grid-template-columns:repeat(3,1fr); gap:12px">
            @foreach([1,2,3,4,5,6,7,8,9] as $n)
            <button class="numpad-btn" onclick="pressDigit('{{ $n }}')">{{ $n }}</button>
            @endforeach
            <div></div>
            <button class="numpad-btn" onclick="pressDigit('0')">0</button>
            <button class="numpad-btn numpad-del" onclick="deleteDigit()">⌫</button>
        </div>
    </div>

    <form method="POST" action="{{ route('warehouse.logout') }}" style="margin-top:24px">
        @csrf
        <button type="submit" style="background:none; border:none; color:#94a3b8; font-size:13px; cursor:pointer; padding:8px">
            Ieși din cont
        </button>
    </form>
</div>
@endsection

@section('scripts')
<style>
    .numpad-btn {
        height: 72px;
        border-radius: 16px;
        border: none;
        background: #f1f5f9;
        font-size: 26px;
        font-weight: 600;
        color: #1e293b;
        cursor: pointer;
        transition: background 0.1s, transform 0.08s;
        -webkit-tap-highlight-color: transparent;
    }
    .numpad-btn:active { background: #e2e8f0; transform: scale(0.94); }
    .numpad-del { font-size: 22px; color: #64748b; }
    .numpad-btn:disabled { opacity: 0.4; }
</style>
<script>
    let pin = '';
    let submitting = false;

    function updateDots(color) {
        const c = color || '#b91c1c';
        document.querySelectorAll('.pin-dot').forEach((dot, i) => {
            dot.style.background   = i < pin.length ? c : 'white';
            dot.style.borderColor  = i < pin.length ? c : '#cbd5e1';
        });
    }

    function setNumpadDisabled(disabled) {
        document.querySelectorAll('.numpad-btn').forEach(b => b.disabled = disabled);
    }

    function pressDigit(d) {
        if (pin.length >= 4 || submitting) return;
        pin += d;
        updateDots();
        if (pin.length === 4) submitPin();
    }

    function deleteDigit() {
        if (submitting) return;
        pin = pin.slice(0, -1);
        updateDots();
        document.getElementById('pin-error').style.display = 'none';
    }

    async function submitPin() {
        submitting = true;
        setNumpadDisabled(true);

        try {
            const res = await fetch('{{ route("warehouse.pin.verify") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ pin }),
            });
            const data = await res.json();

            if (data.ok) {
                sessionStorage.setItem('wh_pin_ok', '1');
                sessionStorage.setItem('wh_pin_ts', String(Date.now()));
                const params = new URLSearchParams(window.location.search);
                window.location.href = params.get('next') || '/wh/';
            } else {
                updateDots('#dc2626');
                setTimeout(() => {
                    pin = '';
                    updateDots();
                    const err = document.getElementById('pin-error');
                    err.textContent = data.error || 'PIN incorect.';
                    err.style.display = 'block';
                    submitting = false;
                    setNumpadDisabled(false);
                }, 400);
            }
        } catch(e) {
            pin = '';
            updateDots();
            submitting = false;
            setNumpadDisabled(false);
        }
    }

    // Physical keyboard support
    document.addEventListener('keydown', e => {
        if (e.key >= '0' && e.key <= '9') pressDigit(e.key);
        if (e.key === 'Backspace') deleteDigit();
    });
</script>
@endsection
