{{-- Buton „Alege Easybox pe hartă" — deschide harta custom (Leaflet).
     Overlay-ul + harta sunt create o singură dată de /js/sameday-locker-map.js (global).
     Parametri:
       $lockerField  — câmpul Livewire de setat cu ID-ul căsuței (default: locker_last_mile)
       $serviceField — câmpul serviciului de comutat pe Locker NextDay (opțional; gol = nu se atinge) --}}
<div>
    <button
        type="button"
        onclick="samedayOpenLockerMap(this)"
        data-locker-field="{{ $lockerField ?? 'locker_last_mile' }}"
        data-service-field="{{ $serviceField ?? '' }}"
        class="fi-btn inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />
        </svg>
        Alege Easybox pe hartă
    </button>
</div>
