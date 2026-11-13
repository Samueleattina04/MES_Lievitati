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

        $res = $this->tracciabilita->albero($lotto, $proporziona);
        if (! ($res['trovato'] ?? false)) {
            return ['ok' => false, 'errore' => "Nessun movimento trovato per il lotto {$lotto}."];
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
        if ($produzioni === []) {
            return ['ok' => false, 'errore' => 'Tutte le produzioni del lotto risultano già scaricate in Omni: nessuna riga da esportare.'];
        }

        return ['ok' => true, 'xlsx' => XlsxWriter::scrivi(OmniExport::fogli($produzioni, (array) config('mes.export.omni')))];
    }
}
