<?php

declare(strict_types=1);

namespace App\Export\Templates;

use App\Export\Contracts\ExportTemplateInterface;
use App\Models\OrdineProduzione;

/**
 * Dichiarazione consumi con lotti (backflush) per fase/ordine (§10). CSV separato da ';'.
 */
final class ConsumiLottiCsvTemplate implements ExportTemplateInterface
{
    public function chiave(): string
    {
        return 'consumi_lotti_csv';
    }

    public function etichetta(): string
    {
        return 'Consumi con lotti (backflush) - CSV';
    }

    public function nomeFile(OrdineProduzione $ordine): string
    {
        return "consumi_{$ordine->numero}.csv";
    }

    public function mime(): string
    {
        return 'text/csv';
    }

    public function contenuto(OrdineProduzione $ordine): string
    {
        $ordine->loadMissing(['fasi.materiali.consumo.lotti']);

        $righe = [['Ordine', 'Fase', 'Articolo', 'UdM', 'QtaEffettiva', 'Lotto', 'QtaLotto']];

        foreach ($ordine->fasi as $fase) {
            foreach ($fase->materiali as $materiale) {
                $consumo = $materiale->consumo;
                if ($consumo === null) {
                    continue;
                }
                if ($consumo->lotti->isNotEmpty()) {
                    foreach ($consumo->lotti as $lotto) {
                        $righe[] = [
                            $ordine->numero, $fase->articolo_prodotto_codice, $materiale->articolo_codice,
                            $materiale->udm, $consumo->quantita_effettiva, $lotto->lotto, $lotto->quantita,
                        ];
                    }
                } else {
                    $righe[] = [
                        $ordine->numero, $fase->articolo_prodotto_codice, $materiale->articolo_codice,
                        $materiale->udm, $consumo->quantita_effettiva, '', '',
                    ];
                }
            }
        }

        return CsvWriter::scrivi($righe);
    }
}
