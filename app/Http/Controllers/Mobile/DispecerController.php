<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Winmentor\VanzariAziService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DispecerController extends Controller
{
    /** Ecran dispecerizare vânzări azi (hub PWA). */
    public function index(Request $request, VanzariAziService $service)
    {
        $zi     = $request->query('zi', Carbon::now('Europe/Bucharest')->toDateString());
        $tip    = (string) $request->query('tip', '');
        $filtru = (string) $request->query('filtru', '');
        $q      = (string) $request->query('q', '');

        if ($request->boolean('refresh')) {
            $service->reimprospateaza($zi);
        }

        $data = $service->pagina($zi, $tip, $filtru, $q);

        return view('app.dispecer', array_merge($data, ['filtru' => $filtru, 'tip' => $tip, 'q' => $q]));
    }

    /** Salvează alocările pe surse pentru o linie (split pe cantitate). */
    public function set(Request $request, VanzariAziService $service)
    {
        $data = $request->validate([
            'tip_doc'         => 'required|string',
            'doc_id'          => 'required|string',
            'pozitie'         => 'required|string',
            'alocari'         => 'required|array',
            'alocari.magazin' => 'nullable|numeric',
            'alocari.depozit' => 'nullable|numeric',
            'alocari.livrare' => 'nullable|numeric',
            'zi'              => 'nullable|date',
        ]);

        $service->salveazaAlocari(
            $data['tip_doc'], $data['doc_id'], $data['pozitie'],
            $data['alocari'], $data['zi'] ?? null, Auth::id()
        );

        return response()->json(['ok' => true]);
    }

    /** Confirmă documentul pentru predare (→ depozit/livrare). */
    public function confirma(Request $request, VanzariAziService $service)
    {
        $data = $request->validate([
            'tip_doc' => 'required|string',
            'doc_id'  => 'required|string',
            'zi'      => 'nullable|date',
        ]);

        $n = $service->confirmaDocument($data['tip_doc'], $data['doc_id'], $data['zi'] ?? null, Auth::id());

        return response()->json(['ok' => $n > 0, 'n' => $n]);
    }

    /** Pagina manipulanților/depozitului. */
    public function dePredat(Request $request, VanzariAziService $service)
    {
        $zi    = $request->query('zi', Carbon::now('Europe/Bucharest')->toDateString());
        $sursa = (string) $request->query('sursa', '');

        return view('app.de-predat', [
            'documente' => $service->dePredat($zi, $sursa ?: null),
            'zi'        => $zi,
            'sursa'     => $sursa,
        ]);
    }

    /** Predă tot restul unei surse dintr-un document. */
    public function predat(Request $request, VanzariAziService $service)
    {
        $data = $request->validate([
            'tip_doc' => 'required|string',
            'doc_id'  => 'required|string',
            'sursa'   => 'required|in:magazin,depozit,livrare',
        ]);

        $service->marcheazaPredat($data['tip_doc'], $data['doc_id'], $data['sursa'], Auth::id());

        return response()->json(['ok' => true]);
    }

    /** Predare pe produs — parțială (cu cant) sau tot restul (fără cant). */
    public function predatLinie(Request $request, VanzariAziService $service)
    {
        $data = $request->validate([
            'tip_doc' => 'required|string',
            'doc_id'  => 'required|string',
            'pozitie' => 'required|string',
            'sursa'   => 'required|in:magazin,depozit,livrare',
            'cant'    => 'nullable|numeric|min:0',
        ]);

        if (isset($data['cant']) && $data['cant'] > 0) {
            $service->predaCantitate($data['tip_doc'], $data['doc_id'], $data['pozitie'], $data['sursa'], (float) $data['cant'], Auth::id());
        } else {
            $service->marcheazaLiniePredat($data['tip_doc'], $data['doc_id'], $data['pozitie'], $data['sursa'], Auth::id());
        }

        return response()->json(['ok' => true]);
    }
}
