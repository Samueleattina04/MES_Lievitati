<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Omni\TraduttoreLottiOmni;
use App\Support\XlsxWriter;
use App\Tracciabilita\OmniExport;
use App\Tracciabilita\TracciabilitaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Tracciabilita' lotto dal gestionale (§6-bis): dato il lotto di un prodotto finito, ricostruisce
 * carichi e scarichi risalendo l'intera distinta dai movimenti di magazzino ESOLVER, e genera il file
 * per l'importazione in Omni (una riga per lotto di produzione, componenti in orizzontale).
 */
class TracciabilitaController extends Controller
{
    public function index(Request $request, TracciabilitaService $tracciabilita): Response
    {
        $lotto = trim((string) $request->query('lotto', ''));

        return Inertia::render('Tracciabilita/Index', [
            'lotto' => $lotto,
            'risultato' => $lotto !== '' ? $tracciabilita->albero($lotto) : null,
            'omniPronto' => true,
        ]);
    }

    /** Scarica il file per l'importazione in Omni per il lotto indicato. */
    public function omni(Request $request, TracciabilitaService $tracciabilita, TraduttoreLottiOmni $traduttore): SymfonyResponse
    {
        $lotto = trim((string) $request->query('lotto', ''));
        if ($lotto === '') {
            return back()->with('error', 'Indicare un lotto per generare il file Omni.');
        }

        try {
            $res = $tracciabilita->albero($lotto);
            if (! $res['trovato']) {
                return back()->with('error', "Nessun movimento trovato per il lotto {$lotto}.");
            }

            // Traduce i lotti fornitore (ESOLVER) nei lotti Omni (FIFO dal DB Omni); i semilavorati
            // non presenti tra i carichi restano col loro lotto.
            $produzioni = $traduttore->applica($res['produzioni']);

            $xlsm = XlsxWriter::scrivi(OmniExport::fogli($produzioni, (array) config('mes.export.omni')), macroEnabled: true);

            return response($xlsm, 200, [
                'Content-Type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                'Content-Disposition' => 'attachment; filename="TracciabilitaIbrida.xlsm"',
            ]);
        } catch (Throwable $e) {
            Log::error('Export Omni fallito', ['lotto' => $lotto, 'errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);

            return back()->with('error', 'Generazione file Omni non riuscita: '.$e->getMessage());
        }
    }
}
