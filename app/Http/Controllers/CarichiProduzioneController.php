<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\CarichiProduzione\CarichiProduzioneExport;
use App\CarichiProduzione\Contracts\CarichiProduzioneSourceInterface;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Carichi di produzione da prima nota (§ Carichi): elenca i versamenti a magazzino (CodOpMag 50/850)
 * creati da un dato operatore (DocElencoGenerale.FirmaCreazOperatore), con la classificazione
 * merceologica (famiglia/macro/microfamiglia). Mostra in pagina conteggio e anteprima, con il
 * pulsante di download del file Excel. Legge ESOLVER in sola lettura.
 */
class CarichiProduzioneController extends Controller
{
    /** Anteprima massima mostrata in pagina (il file Excel contiene tutto). */
    private const ANTEPRIMA = 200;

    public function index(Request $request, CarichiProduzioneSourceInterface $source): Response
    {
        self::estendiTempoEsecuzione();

        $operatore = self::operatore($request);
        $codOpMag = self::codOpMag();

        $risultato = null;
        $errore = null;
        try {
            $carichi = $source->carichi($operatore, $codOpMag);
            $righe = array_map(static fn ($c) => $c->toArray(), $carichi);
            $risultato = [
                'totale' => count($righe),
                'righe' => array_slice($righe, 0, self::ANTEPRIMA),
                'anteprima' => self::ANTEPRIMA,
            ];
        } catch (Throwable $e) {
            Log::error('Carichi di produzione falliti', ['errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);
            $errore = 'Errore nel recupero dei carichi di produzione: '.$e->getMessage();
        }

        return Inertia::render('CarichiProduzione/Index', [
            'operatore' => $operatore,
            'codOpMag' => $codOpMag,
            'risultato' => $risultato,
            'errore' => $errore,
        ]);
    }

    /** Scarica il file Excel dei carichi di produzione (un foglio "Carichi"). */
    public function scarica(Request $request, CarichiProduzioneSourceInterface $source): SymfonyResponse
    {
        self::estendiTempoEsecuzione();

        try {
            $carichi = $source->carichi(self::operatore($request), self::codOpMag());
            $xlsx = XlsxWriter::scrivi(CarichiProduzioneExport::fogli($carichi));

            return response($xlsx, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="CarichiProduzione.xlsx"',
            ]);
        } catch (Throwable $e) {
            Log::error('Export carichi di produzione fallito', ['errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);

            return back()->with('error', 'Generazione del file dei carichi non riuscita: '.$e->getMessage());
        }
    }

    /** Operatore creatore del documento: dalla query (?operatore=) o dal default di config. */
    private static function operatore(Request $request): string
    {
        $default = (string) config('mes.carichi_produzione.operatore', '');
        $op = $request->has('operatore') ? (string) $request->query('operatore') : $default;

        return trim($op);
    }

    /** @return list<string> codici operazione magazzino ammessi (da config). */
    private static function codOpMag(): array
    {
        return array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) config('mes.carichi_produzione.cod_op_mag', []),
        ), static fn (string $v) => $v !== ''));
    }

    /** La lettura dei movimenti ESOLVER puo' superare i 30s di default: alza il limite. */
    private static function estendiTempoEsecuzione(): void
    {
        $secondi = (int) config('mes.carichi_produzione.timeout', 180);
        if ($secondi > 0 && function_exists('set_time_limit')) {
            @set_time_limit($secondi);
        }
    }
}
