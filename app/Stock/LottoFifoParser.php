<?php

declare(strict_types=1);

namespace App\Stock;

/**
 * Estrae dal codice lotto la chiave cronologica per l'ordinamento FIFO (§5.2).
 *
 * Nel gestionale i campi dedicati sono inutilizzabili (RifLottoNum sempre 0, RifLottoData sentinella
 * 1800-01-01): la data e' invece CODIFICATA nel codice lotto secondo lo standard aziendale:
 *
 *   <prodotto/fornitore>-<GIORNO><ANNO><PROGRESSIVO>
 *     - dopo l'ultimo '-', tutte cifre;
 *     - ultime 3 cifre  = progressivo pedana (110, 111, ... per pedane dello stesso lotto);
 *     - 2 cifre prima   = anno (es. 26 = 2026);
 *     - cifre iniziali  = giorno dell'anno (1-366, senza zeri iniziali: "76", "111").
 *   Esempio: 7317-11126110 -> giorno 111, anno 26, progressivo 110.
 *
 * NB: per ORDINARE non serve sapere se il giorno e' solare o giuliano: la numerazione cresce nel
 * tempo, quindi (anno, giorno, progressivo) crescente = dal piu' vecchio al piu' recente. Nessuna
 * conversione in data reale.
 *
 * I codici che NON rispettano il formato (es. lotti di fornitori esterni tipo "3126000272")
 * restituiscono null: l'adapter li ordina in coda, senza rompere la proposta.
 */
final class LottoFifoParser
{
    /**
     * @return array{anno:int, giorno:int, prog:int}|null
     */
    public static function chiave(string $lotto): ?array
    {
        $lotto = trim($lotto);
        $pos = strrpos($lotto, '-');
        if ($pos === false) {
            return null;
        }

        $coda = substr($lotto, $pos + 1);
        // Solo cifre e lunghezza sufficiente (>= 6: almeno 1 giorno + 2 anno + 3 progressivo).
        if ($coda === '' || ! ctype_digit($coda) || strlen($coda) < 6) {
            return null;
        }

        $len = strlen($coda);
        $giorno = (int) substr($coda, 0, $len - 5);
        $anno = (int) substr($coda, $len - 5, 2);
        $prog = (int) substr($coda, $len - 3, 3);

        if ($giorno < 1 || $giorno > 366) {
            return null;
        }

        return ['anno' => 2000 + $anno, 'giorno' => $giorno, 'prog' => $prog];
    }

    /**
     * Confronto FIFO (crescente = piu' vecchio prima). I codici non parsabili vanno in coda.
     *
     * @param array{anno:int, giorno:int, prog:int}|null $a
     * @param array{anno:int, giorno:int, prog:int}|null $b
     */
    public static function confronta(?array $a, ?array $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1; // non parsabile: dopo i lotti con data nota
        }
        if ($b === null) {
            return -1;
        }

        return [$a['anno'], $a['giorno'], $a['prog']] <=> [$b['anno'], $b['giorno'], $b['prog']];
    }
}
