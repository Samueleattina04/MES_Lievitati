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
     * @param  array{soloEsolver:list<array<string,string>>, soloOmni:list<array<string,string>>}  $risultato
     * @return list<array{name:string, rows:list<list<string|int|float|null>>}>
     */
    public static function fogli(array $risultato): array
    {
        return [
            ['name' => 'Esolver non in Omni', 'rows' => self::righeEsolver($risultato['soloEsolver'] ?? [])],
            ['name' => 'Omni non in Esolver', 'rows' => self::righeOmni($risultato['soloOmni'] ?? [])],
        ];
    }

    /**
     * @param  list<array<string,string>>  $articoli
     * @return list<list<string|int|float|null>>
     */
    private static function righeEsolver(array $articoli): array
    {
        $righe = [['Codice articolo', 'Descrizione']];
        foreach ($articoli as $a) {
            $righe[] = [(string) ($a['codice'] ?? ''), (string) ($a['descrizione'] ?? '')];
        }

        return $righe;
    }

    /**
     * Foglio Omni: in piu' la tabella di provenienza e l'id d'origine dell'articolo.
     *
     * @param  list<array<string,string>>  $articoli
     * @return list<list<string|int|float|null>>
     */
    private static function righeOmni(array $articoli): array
    {
        $righe = [['Codice articolo', 'Descrizione', 'Tabella', 'ID']];
        foreach ($articoli as $a) {
            $righe[] = [
                (string) ($a['codice'] ?? ''),
                (string) ($a['descrizione'] ?? ''),
                (string) ($a['tabella'] ?? ''),
                (string) ($a['id'] ?? ''),
            ];
        }

        return $righe;
    }
}
