<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Tracciabilita\TracciabilitaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tracciabilita' lotto dal gestionale (§6-bis): dato il lotto di un prodotto finito, ricostruisce
 * carichi e scarichi risalendo l'intera distinta dai movimenti di magazzino ESOLVER. Base per il
 * futuro export nel tracciato Omni.
 */
class TracciabilitaController extends Controller
{
    public function index(Request $request, TracciabilitaService $tracciabilita): Response
    {
        $lotto = trim((string) $request->query('lotto', ''));

        return Inertia::render('Tracciabilita/Index', [
            'lotto' => $lotto,
            'risultato' => $lotto !== '' ? $tracciabilita->albero($lotto) : null,
            // Omni: bottone di export in attesa del tracciato reale (nessun formato configurato).
            'omniPronto' => false,
        ]);
    }
}
