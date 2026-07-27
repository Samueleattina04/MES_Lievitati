<?php

declare(strict_types=1);

namespace App\Export\Templates;

use App\Export\Contracts\ExportTemplateInterface;
use App\Models\OrdineProduzione;

/**
 * Tracciato ESOLVER: versamenti a magazzino dei lotti prodotti (carico), §10. Struttura dedotta dal
 * file reale "lievitati 30-06-2026.csv": CSV separato da ';' con ';' finale, SENZA BOM (il gestionale
 * non lo gradisce), righe terminate da CRLF. Riga 1 = intestazione fissa (7 colonne); poi una riga per
 * ogni lotto prodotto:
 *   causale ; data(gg/mm/aaaa) ; codice articolo ; quantita(con virgola) ; lotto ; col6 ; col7 ;
 * Le costanti (causale, intestazione, col6, col7) sono in config/mes.php ('export.esolver').
 */
final class EsolverVersamentiCsvTemplate implements ExportTemplateInterface
{
    public function chiave(): string
    {
        return 'esolver_versamenti';
    }

    public function etichetta(): string
    {
        return 'ESOLVER — versamenti a magazzino';
    }

    public function nomeFile(OrdineProduzione $ordine): string
    {
        return "esolver_{$ordine->numero}.csv";
    }

    public function mime(): string
    {
        return 'text/csv';
    }

    public function contenuto(OrdineProduzione $ordine): string
    {
        $cfg = (array) config('mes.export.esolver');
        $intestazione = (string) ($cfg['intestazione'] ?? '10;20;150;180;270;260;140');
        $causale = (string) ($cfg['causale'] ?? '103');
        $col6 = (string) ($cfg['col6'] ?? '01');
        $col7 = (string) ($cfg['col7'] ?? '850');

        $ordine->loadMissing(['fasi.lottiProdotto']);
        $data = $this->dataVersamento($ordine);

        $righe = [$intestazione]; // riga 1: intestazione fissa del tracciato
        foreach ($ordine->fasi as $fase) {
            foreach ($fase->lottiProdotto as $lotto) {
                $righe[] = implode(';', [
                    $causale,
                    $data,
                    $fase->articolo_prodotto_codice,
                    $this->numero($lotto->quantita ?? $fase->quantita_prodotta),
                    $lotto->lotto,
                    $col6,
                    $col7,
                ]);
            }
        }

        // Ogni riga termina con ';' e CRLF; nessun BOM (a differenza del CsvWriter generico).
        return implode('', array_map(static fn (string $r) => $r.";\r\n", $righe));
    }

    /** Data del versamento (gg/mm/aaaa): completamento produzione dell'ordine, fallback a oggi. */
    private function dataVersamento(OrdineProduzione $ordine): string
    {
        $fine = $ordine->fasi->pluck('timestamp_fine')->filter()->max();

        return ($fine ?? now())->format('d/m/Y');
    }

    /** Numero con virgola decimale e zeri finali rimossi (0.250000 -> "0,25", 2.000000 -> "2"). */
    private function numero(int|float|string|null $valore): string
    {
        $s = rtrim(rtrim(number_format((float) $valore, 6, '.', ''), '0'), '.');

        return str_replace('.', ',', $s === '' ? '0' : $s);
    }
}
