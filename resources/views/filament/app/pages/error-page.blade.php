<x-filament-panels::page>
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 5rem 0; text-align: center;">

        <div style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; width: 6rem; height: 6rem; border-radius: 9999px; {{ $code === 403 ? 'background: #fef2f2;' : 'background: #fffbeb;' }}">
            @if($code === 403)
                <x-filament::icon icon="heroicon-o-lock-closed" style="width: 3rem; height: 3rem; color: #ef4444;" />
            @elseif($code === 404)
                <x-filament::icon icon="heroicon-o-magnifying-glass" style="width: 3rem; height: 3rem; color: #f59e0b;" />
            @else
                <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width: 3rem; height: 3rem; color: #f59e0b;" />
            @endif
        </div>

        <h1 style="font-size: 1.5rem; font-weight: 700; color: #111827; margin-bottom: 0.75rem;">
            @if($code === 403)
                Nu ai acces la aceasta pagina
            @elseif($code === 404)
                Pagina nu a fost gasita
            @else
                A aparut o eroare
            @endif
        </h1>

        <p style="color: #6b7280; font-size: 1rem; max-width: 28rem; margin-bottom: 2rem;">
            @if($code === 403)
                Daca crezi ca este o greseala, contacteaza un administrator.
            @elseif($code === 404)
                Pagina pe care o cauti nu exista sau a fost mutata.
            @else
                Te rugam sa incerci din nou sau contacteaza un administrator.
            @endif
        </p>

        <a
            href="{{ url('/') }}"
            style="display: inline-grid; grid-auto-flow: column; align-items: center; gap: 0.375rem; font-weight: 600; border-radius: 0.5rem; padding: 0.5rem 1rem; background: #8B1A1A; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.05); text-decoration: none; transition: background 0.15s;"
            onmouseover="this.style.background='#a52020'"
            onmouseout="this.style.background='#8B1A1A'"
        >
            <x-filament::icon icon="heroicon-o-home" style="width: 1rem; height: 1rem;" />
            Inapoi la dashboard
        </a>

    </div>
</x-filament-panels::page>
