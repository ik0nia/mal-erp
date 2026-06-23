<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class HubController extends Controller
{
    /**
     * Ecranul „home" al aplicației unificate — grilă de icoane.
     * Tile-urile pot fi filtrate per utilizator/rol prin closure-ul `visible`.
     */
    public function home()
    {
        $user = Auth::user();

        $tiles = collect([
            [
                'key'     => 'receptie',
                'label'   => 'Recepție marfă',
                'desc'    => 'Recepție cantitativă comenzi furnizori',
                'icon'    => '📦',
                'url'     => '/wh/',
                'color'   => '#b91c1c',
                'visible' => true,
            ],
            [
                'key'     => 'verificare',
                'label'   => 'Verificare produs',
                'desc'    => 'Lookup stoc, preț și comenzi',
                'icon'    => '🔍',
                'url'     => '/inv/',
                'color'   => '#0f172a',
                'visible' => true,
            ],
            [
                'key'     => 'dispecer',
                'label'   => 'Dispecerizare',
                'desc'    => 'Vânzări azi: magazin / depozit / livrare',
                'icon'    => '🚦',
                'url'     => '/app/dispecer',
                'color'   => '#0e7490',
                'visible' => true,
            ],
            [
                'key'     => 'depredat',
                'label'   => 'De predat',
                'desc'    => 'Marfă de pregătit / încărcat',
                'icon'    => '🚚',
                'url'     => '/app/de-predat',
                'color'   => '#b45309',
                'visible' => true,
            ],
        ])
            ->filter(fn ($t) => $t['visible'])
            ->values();

        return view('app.home', [
            'user'  => $user,
            'tiles' => $tiles,
        ]);
    }
}
