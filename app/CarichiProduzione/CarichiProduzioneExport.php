<?php

declare(strict_types=1);

namespace App\CarichiProduzione;

/**
 * Costruisce il file Excel dei carichi di produzione (§ Carichi) per {@see \App\Support\XlsxWriter::scrivi()}:
 * un foglio "Carichi" con intestazione e una riga per carico. Include i dati del carico (come nella
 * tracciabilita') piu' operatore creatore e classificazione merceologica (famiglia/macro/microfamiglia).
 */
final class CarichiProduzioneExport
{
    private const INTESTAZIONE = [
        'Data', 'Codice articolo', 'Descrizione', 'Lotto', 'Lotto PF', 'Quantità', 'UM',
        'Magazzino', 'Causale', 'Cod.Op.Mag', 'Operatore', 'Famiglia', 'Macrofamiglia', 'Microfamiglia',
    ];

    /**
     * @param  list<CaricoProduzione>  $carichi
     * @return list<array{name:string, rows:list<list<string|int|float|null>>}>
     */
    public static function fogli(array $carichi): array
    {
        $righe = [self::INTESTAZIONE];

        foreach ($carichi as $c) {
            $righe[] = [
                self::soloData($c->data),
                $c->articolo,
                $c->descrizione,
                $c->lotto,
                $c->lottoPf,
                $c->quantita,
                $c->um,
                $c->magazzino,
                $c->causale,
                $c->codOpMag,
                $c->operatore,
                $c->famiglia,
                $c->macrofamiglia,
                $c->microfamiglia,
            ];
        }

        return [
            ['name' => 'Carichi', 'rows' => $righe],
        ];
    }

    /** Nel file teniamo solo la data (senza orario), coerente con la tabella a video. */
    private static function soloData(?string $data): string
    {
        if ($data === null || $data === '') {
            return '';
        }

        return explode(' ', $data)[0];
    }
}
