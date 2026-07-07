<?php

declare(strict_types=1);

namespace App\Export\Templates;

use App\Export\Contracts\ExportTemplateInterface;
use App\Models\OrdineProduzione;

/**
 * Versamento a magazzino di semilavorati e prodotto finito, con lotto (§10). CSV.
 */
final class VersamentiCsvTemplate implements ExportTemplateInterface
{
    public function chiave(): string
    {
        return 'versamenti_csv';
    }

    public function etichetta(): string
    {
        return 'Versamenti a magazzino con lotto - CSV';
    }

    public function nomeFile(OrdineProduzione $ordine): string
    {
        return "versamenti_{$ordine->numero}.csv";
    }

    public function mime(): string
    {
        return 'text/csv';
    }

    public function contenuto(OrdineProduzione $ordine): string
    {
        $ordine->loadMissing(['fasi.lottiProdotto']);

        $righe = [['Ordine', 'ArticoloProdotto', 'Lotto', 'QtaProdotta', 'UdM', 'Radice']];

        foreach ($ordine->fasi as $fase) {
            foreach ($fase->lottiProdotto as $lotto) {
                $righe[] = [
                    $ordine->numero,
                    $fase->articolo_prodotto_codice,
                    $lotto->lotto,
                    $lotto->quantita ?? $fase->quantita_prodotta,
                    $fase->udm,
                    $fase->articolo_prodotto_codice === $ordine->articolo_finito_codice ? 'SI' : '',
                ];
            }
        }

        return CsvWriter::scrivi($righe);
    }
}
