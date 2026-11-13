<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Tracciabilita\TracciabilitaOmniExport;
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
 * per l'importazione in Omni (una riga per lotto di produzione, componenti in orizzontale). La
 * costruzione del file Omni e il check "gia' scaricato" sono nel servizio TracciabilitaOmniExport.
 */
class TracciabilitaController extends Controller
{
    public function index(Request $request, TracciabilitaService $tracciabilita, TracciabilitaOmniExport $omni): Response
    {
        self::estendiTempoEsecuzione();

        $lotto = trim((string) $request->query('lotto', ''));
        // Interruttore per i test: ?proporziona=0 mostra le quantita' grezze (impasto intero).
        $proporziona = $request->boolean('proporziona', true);

        $risultato = null;
        $errore = null;
        $scarichi = [];
        if ($lotto !== '') {
            try {
                $risultato = $tracciabilita->albero($lotto, $proporziona);
                // Produzioni gia' scaricate in Omni (badge + esclusione nel file): protetto, non fa
                // fallire la pagina se Omni e' irraggiungibile.
                $scarichi = $omni->scarichiGiaPresenti($risultato['produzioni'] ?? []);
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
            'proporziona' => $proporziona,
            // Mappa lottoProduzione => n. scarichi gia' presenti in Omni (solo quelli > 0).
            'scarichiOmni' => $scarichi,
        ]);
    }

    /** Scarica il file per l'importazione in Omni per il lotto indicato. */
    public function omni(Request $request, TracciabilitaOmniExport $omni): SymfonyResponse
    {
        self::estendiTempoEsecuzione();

        $lotto = trim((string) $request->query('lotto', ''));
        $proporziona = $request->boolean('proporziona', true);

        try {
            $res = $omni->fileOmniPerLotto($lotto, $proporziona);
            if (! $res['ok']) {
                return back()->with('error', $res['errore'] ?? 'Generazione file Omni non riuscita.');
            }

            return response($res['xlsx'], 200, [
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
