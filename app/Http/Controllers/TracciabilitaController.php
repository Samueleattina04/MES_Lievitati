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
        self::estendiTempoEsecuzione();

        $lotto = trim((string) $request->query('lotto', ''));

        $risultato = null;
        $errore = null;
        if ($lotto !== '') {
            try {
                $risultato = $tracciabilita->albero($lotto);
            } catch (Throwable $e) {
                Log::error('Tracciabilità fallita', [
                    'lotto' => $lotto,
                    'errore' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);
                $errore = 'Errore nel recupero della tracciabilità: '.$e->getMessage();
            }
        }

        return Inertia::render('Tracciabilita/Index', [
            'lotto' => $lotto,
            'risultato' => $risultato,
            'omniPronto' => true,
            'errore' => $errore,
        ]);
    }

    /** Scarica il file per l'importazione in Omni per il lotto indicato. */
    public function omni(Request $request, TracciabilitaService $tracciabilita, TraduttoreLottiOmni $traduttore): SymfonyResponse
    {
        self::estendiTempoEsecuzione();

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

            $xlsx = XlsxWriter::scrivi(OmniExport::fogli($produzioni, (array) config('mes.export.omni')));

            return response($xlsx, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="TracciabilitaIbrida.xlsx"',
            ]);
        } catch (Throwable $e) {
            Log::error('Export Omni fallito', ['lotto' => $lotto, 'errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);

            return back()->with('error', 'Generazione file Omni non riuscita: '.$e->getMessage());
        }
    }

    /**
     * Un albero grande fa piu' scansioni sui movimenti ESOLVER: il default PHP di 30s non basta.
     * Alza il limite (config, default 180s). Su Windows/IIS il tempo di attesa sulle query di rete
     * conta nel max_execution_time, quindi qui e' il punto giusto per estenderlo.
     */
    private static function estendiTempoEsecuzione(): void
    {
        $secondi = (int) config('mes.tracciabilita.timeout', 180);
        if ($secondi > 0 && function_exists('set_time_limit')) {
            @set_time_limit($secondi);
        }
    }
}
