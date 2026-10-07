<?php

declare(strict_types=1);

namespace App\Check;

/**
 * Costruisce il file di confronto anagrafiche (§ Check) per {@see \App\Support\XlsxWriter::scrivi()}:
 * due fogli — "Esolver non in Omni" e "Omni non in Esolver" — ciascuno con intestazione
 * [Codice articolo, Descrizione] e una riga per articolo.
 */
final class CheckExport
{
    /**
     * @param  array{soloEsolver:list<array{codice:string,descrizione:string}>, soloOmni:list<array{codice:string,descrizione:string}>}  $risultato
     * @return list<array{name:string, rows:list<list<string|int|float|null>>}>
     */
    public static function fogli(array $risultato): array
    {
        return [
            ['name' => 'Esolver non in Omni', 'rows' => self::righe($risultato['soloEsolver'] ?? [])],
            ['name' => 'Omni non in Esolver', 'rows' => self::righe($risultato['soloOmni'] ?? [])],
        ];
    }

    /**
     * @param  list<array{codice:string,descrizione:string}>  $articoli
     * @return list<list<string|int|float|null>>
     */
    private static function righe(array $articoli): array
    {
        $righe = [['Codice articolo', 'Descrizione']];
        foreach ($articoli as $a) {
            $righe[] = [(string) ($a['codice'] ?? ''), (string) ($a['descrizione'] ?? '')];
        }

        return $righe;
    }
}
