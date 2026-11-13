<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\CarichiProduzione\CarichiProduzioneExport;
use App\CarichiProduzione\Contracts\CarichiProduzioneSourceInterface;
use App\CarichiProduzione\FiltroFamiglie;
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
        [$da, $a] = self::intervallo($request);
        $selezionate = self::selezioniFamiglie($request);

        $risultato = null;
        $errore = null;
        try {
            $tutti = $source->carichi($operatore, $codOpMag, $da, $a);
            $filtrati = FiltroFamiglie::applica($tutti, $selezionate);
            $righe = array_map(static fn ($c) => $c->toArray(), $filtrati);
            $risultato = [
                'totale' => count($righe),
                'righe' => array_slice($righe, 0, self::ANTEPRIMA),
                'anteprima' => self::ANTEPRIMA,
                // Opzioni per le checklist: valori presenti nel periodo (prima del filtro famiglie).
                'opzioni' => FiltroFamiglie::opzioni($tutti),
            ];
        } catch (Throwable $e) {
            Log::error('Carichi di produzione falliti', ['errore' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);
            $errore = 'Errore nel recupero dei carichi di produzione: '.$e->getMessage();
        }

        return Inertia::render('CarichiProduzione/Index', [
            'operatore' => $operatore,
            'codOpMag' => $codOpMag,
            'da' => $da,
            'a' => $a,
            'selezionate' => $selezionate,
            'risultato' => $risultato,
            'errore' => $errore,
        ]);
    }

    /** Scarica il file Excel dei carichi di produzione (un foglio "Carichi"). */
    public function scarica(Request $request, CarichiProduzioneSourceInterface $source): SymfonyResponse
    {
        self::estendiTempoEsecuzione();

        try {
            [$da, $a] = self::intervallo($request);
            $tutti = $source->carichi(self::operatore($request), self::codOpMag(), $da, $a);
            $filtrati = FiltroFamiglie::applica($tutti, self::selezioniFamiglie($request));
            $xlsx = XlsxWriter::scrivi(CarichiProduzioneExport::fogli($filtrati));

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

    /**
     * Selezioni checklist famiglie da ?fam[]=/?macro[]=/?micro[]=. Gruppo assente o vuoto = nessun
     * filtro (mostra tutto).
     *
     * @return array{famiglia:list<string>, macrofamiglia:list<string>, microfamiglia:list<string>}
     */
    private static function selezioniFamiglie(Request $request): array
    {
        return [
            'famiglia' => self::arrayParam($request, 'fam'),
            'macrofamiglia' => self::arrayParam($request, 'macro'),
            'microfamiglia' => self::arrayParam($request, 'micro'),
        ];
    }

    /**
     * Legge un parametro come lista di stringhe (accetta array o singolo valore), ripulita.
     *
     * @return list<string>
     */
    private static function arrayParam(Request $request, string $chiave): array
    {
        $v = $request->query($chiave, []);
        if (is_string($v)) {
            $v = $v === '' ? [] : [$v];
        }
        if (! is_array($v)) {
            $v = [];
        }

        return array_values(array_filter(array_map(static fn ($x) => trim((string) $x), $v), static fn (string $s) => $s !== ''));
    }

    /**
     * Intervallo date (Y-m-d) da ?da=/?a=, con default agli ultimi N giorni (config giorni_default).
     * Serve a limitare il volume dei carichi: su tutta la storia la query esaurisce la memoria.
     *
     * @return array{0:string,1:string} [da, a]
     */
    private static function intervallo(Request $request): array
    {
        $giorni = max(1, (int) config('mes.carichi_produzione.giorni_default', 90));
        $a = self::dataValida((string) $request->query('a', '')) ?? date('Y-m-d');
        $da = self::dataValida((string) $request->query('da', '')) ?? date('Y-m-d', strtotime("-{$giorni} days"));

        // Se invertite, le riallinea (da <= a).
        if ($da > $a) {
            [$da, $a] = [$a, $da];
        }

        return [$da, $a];
    }

    /** Valida una data 'Y-m-d' (ritorna null se vuota o non valida). */
    private static function dataValida(string $d): ?string
    {
        $d = trim($d);
        if ($d === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $d);

        return ($dt !== false && $dt->format('Y-m-d') === $d) ? $d : null;
    }

    /**
     * La lettura dei movimenti ESOLVER puo' superare i 30s di default e consumare molta memoria:
     * alza tempo e limite di memoria (configurabili).
     */
    private static function estendiTempoEsecuzione(): void
    {
        $secondi = (int) config('mes.carichi_produzione.timeout', 180);
        if ($secondi > 0 && function_exists('set_time_limit')) {
            @set_time_limit($secondi);
        }

        $memoria = trim((string) config('mes.carichi_produzione.memoria', '512M'));
        if ($memoria !== '') {
            @ini_set('memory_limit', $memoria);
        }
    }
}
