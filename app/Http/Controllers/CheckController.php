<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Check\CheckExport;
use App\Check\CheckService;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Check anagrafiche (§ Check): confronta gli articoli presenti su ESOLVER e su Omni e genera un file
 * Excel con due fogli (articoli solo su ESOLVER / solo su Omni). Legge da entrambi i gestionali in
 * sola lettura; mostra in pagina i conteggi e un'anteprima, con il pulsante di download.
 */
class CheckController extends Controller
{
    /** Anteprima massima per foglio mostrata in pagina (il file Excel contiene tutto). */
    private const ANTEPRIMA = 200;

    public function index(CheckService $check): Response
    {
        self::estendiTempoEsecuzione();

        $risultato = null;
        $errore = null;
        try {
            $r = $check->confronta();
            $risultato = [
                'solo_esolver_totale' => count($r['soloEsolver']),
                'solo_omni_totale' => count($r['soloOmni']),
                'solo_esolver' => array_slice($r['soloEsolver'], 0, self::ANTEPRIMA),
                'solo_omni' => array_slice($r['soloOmni'], 0, self::ANTEPRIMA),
                'anteprima' => self::ANTEPRIMA,
            ];
        } catch (Throwable $e) {
            Log::error('Check anagrafiche fallito', ['errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);
            $errore = 'Errore nel confronto delle anagrafiche: '.$e->getMessage();
        }

        return Inertia::render('Check/Index', [
            'risultato' => $risultato,
            'errore' => $errore,
        ]);
    }

    /** Scarica il file Excel di confronto (due fogli: solo ESOLVER / solo Omni). */
    public function scarica(CheckService $check): SymfonyResponse
    {
        self::estendiTempoEsecuzione();

        try {
            $xlsx = XlsxWriter::scrivi(CheckExport::fogli($check->confronta()));

            return response($xlsx, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="CheckArticoli.xlsx"',
            ]);
        } catch (Throwable $e) {
            Log::error('Export Check fallito', ['errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);

            return back()->with('error', 'Generazione del file di confronto non riuscita: '.$e->getMessage());
        }
    }

    /** Leggere due anagrafiche intere puo' richiedere piu' dei 30s di default: alza il limite. */
    private static function estendiTempoEsecuzione(): void
    {
        $secondi = (int) config('mes.check.timeout', 120);
        if ($secondi > 0 && function_exists('set_time_limit')) {
            @set_time_limit($secondi);
        }
    }
}
