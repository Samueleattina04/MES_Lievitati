<?php

declare(strict_types=1);

namespace App\Tracciabilita;

/**
 * Genera il file per l'importazione in Omni (§6-bis), nel formato reale usato dall'azienda: UNA riga
 * per lotto di produzione, i componenti in ORIZZONTALE come `lotto*quantità` (decimale con virgola).
 * Colonne: [data] ; Lotto di Produzione ; comp1 ; comp2 ; ...  (separatore ';', CRLF, senza BOM).
 */
final class OmniExport
{
    /**
     * @param  list<array<string,mixed>>  $produzioni  Da TracciabilitaService::albero()['produzioni'].
     * @param  array<string,mixed>  $opzioni
     */
    public static function csv(array $produzioni, array $opzioni = []): string
    {
        $sep = (string) ($opzioni['separatore'] ?? ';');
        $includiData = (bool) ($opzioni['includi_data'] ?? true);

        $righe = [];
        foreach ($produzioni as $p) {
            $cols = [];
            if ($includiData) {
                $cols[] = (string) ($p['data'] ?? '');
            }
            $cols[] = (string) ($p['lotto'] ?? '');
            foreach ((array) ($p['componenti'] ?? []) as $c) {
                $lotto = trim((string) ($c['lotto'] ?? ''));
                if ($lotto === '') {
                    continue;
                }
                $cols[] = $lotto.'*'.self::qta($c['quantita'] ?? 0);
            }
            $righe[] = implode($sep, $cols);
        }

        return $righe === [] ? '' : implode("\r\n", $righe)."\r\n";
    }

    /** Quantità con virgola decimale e zeri finali rimossi (256.800000 -> "256,8", 1284 -> "1284"). */
    private static function qta(int|float|string $valore): string
    {
        $s = rtrim(rtrim(number_format((float) $valore, 6, '.', ''), '0'), '.');

        return str_replace('.', ',', $s === '' ? '0' : $s);
    }
}
