<?php

declare(strict_types=1);

namespace App\Tracciabilita;

use App\Omni\Contracts\ScarichiOmniSourceInterface;
use App\Omni\TraduttoreLottiOmni;
use App\Support\XlsxWriter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Costruzione del file Omni per un lotto PF e check "gia' scaricato" (§6-bis), condiviso fra la
 * pagina Tracciabilita' e la pagina Carichi. Incapsula: albero -> esclusione produzioni gia'
 * scaricate in Omni -> traduzione lotti -> xlsx. Il check Omni e' protetto (se Omni e' giu' non
 * fa fallire chi lo usa).
 */
final class TracciabilitaOmniExport
{
    public function __construct(
        private readonly TracciabilitaService $tracciabilita,
        private readonly TraduttoreLottiOmni $traduttore,
        private readonly ScarichiOmniSourceInterface $scarichiOmni,
    ) {}

    /**
     * Scarichi gia' presenti in Omni per i lotti di produzione indicati (mappa lotto => n., solo > 0).
     * Protetto: Omni irraggiungibile -> [] (nessun badge/esclusione), non rompe la pagina.
     *
     * @param  list<string>  $lotti
     * @return array<string,int>
     */
    public function scarichiPerLotti(array $lotti): array
    {
        $lotti = array_values(array_unique(array_filter(
            array_map(static fn ($l) => trim((string) $l), $lotti),
            static fn (string $l) => $l !== '',
        )));
        if ($lotti === []) {
            return [];
        }

        try {
            return $this->scarichiOmni->scarichiPerProduzioni($lotti);
        } catch (Throwable $e) {
            Log::warning('Check scarichi Omni non riuscito', ['errore' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Come scarichiPerLotti, ma a partire dalle produzioni di un albero.
     *
     * @param  list<array<string,mixed>>  $produzioni
     * @return array<string,int>
     */
    public function scarichiGiaPresenti(array $produzioni): array
    {
        return $this->scarichiPerLotti(array_map(static fn ($p) => (string) ($p['lotto'] ?? ''), $produzioni));
    }

    /**
     * File Omni per un singolo lotto PF: albero -> esclude le produzioni gia' scaricate -> traduce i
     * lotti -> xlsx. Non lancia per i casi "attesi" (li riporta in 'errore').
     *
     * @return array{ok:bool, xlsx?:string, errore?:string}
     */
    public function fileOmniPerLotto(string $lotto, bool $proporziona = true): array
    {
        $lotto = trim($lotto);
        if ($lotto === '') {
            return ['ok' => false, 'errore' => 'Indicare un lotto per generare il file Omni.'];
        }

        $r = $this->produzioniTradotte($lotto, $proporziona);
        if (! $r['trovato']) {
            return ['ok' => false, 'errore' => "Nessun movimento trovato per il lotto {$lotto}."];
        }
        if ($r['produzioni'] === []) {
            return ['ok' => false, 'errore' => 'Tutte le produzioni del lotto risultano già scaricate in Omni: nessuna riga da esportare.'];
        }

        return ['ok' => true, 'xlsx' => $this->xlsx($r['produzioni'])];
    }

    /**
     * Produzioni Omni (tradotte, escluse le gia' scaricate) di un singolo lotto PF. Vuoto se il lotto
     * non ha movimenti o e' tutto gia' scaricato. Usato per comporre un file Omni cumulativo (Carichi).
     *
     * @return list<array<string,mixed>>
     */
    public function produzioniOmniPerLotto(string $lotto, bool $proporziona = true): array
    {
        return $this->produzioniTradotte(trim($lotto), $proporziona)['produzioni'];
    }

    /**
     * Costruisce il file xlsx Omni da un elenco di produzioni gia' pronte (tradotte).
     *
     * @param  list<array<string,mixed>>  $produzioni
     */
    public function xlsx(array $produzioni): string
    {
        return XlsxWriter::scrivi(OmniExport::fogli($produzioni, (array) config('mes.export.omni')));
    }

    /**
     * Nucleo condiviso: dal lotto PF -> albero -> esclude le produzioni gia' scaricate in Omni ->
     * traduce i lotti fornitore nei lotti Omni.
     *
     * @return array{trovato:bool, produzioni:list<array<string,mixed>>}
     */
    private function produzioniTradotte(string $lotto, bool $proporziona): array
    {
        if ($lotto === '') {
            return ['trovato' => false, 'produzioni' => []];
        }

        $res = $this->tracciabilita->albero($lotto, $proporziona);
        if (! ($res['trovato'] ?? false)) {
            return ['trovato' => false, 'produzioni' => []];
        }

        // Guardia anti doppio-scarico: le produzioni gia' scaricate in Omni si escludono dal file.
        $giaScaricati = $this->scarichiGiaPresenti($res['produzioni']);
        $produzioni = $this->traduttore->applica($res['produzioni']);
        if ($giaScaricati !== []) {
            $produzioni = array_values(array_filter(
                $produzioni,
                static fn (array $p) => ! isset($giaScaricati[(string) ($p['lotto'] ?? '')]),
            ));
        }

        return ['trovato' => true, 'produzioni' => $produzioni];
    }
}
