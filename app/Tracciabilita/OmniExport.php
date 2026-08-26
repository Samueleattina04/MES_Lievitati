<?php

declare(strict_types=1);

namespace App\Tracciabilita;

/**
 * Costruisce i fogli per l'importazione in Omni (§6-bis), nel formato reale aziendale (file
 * "Tracciabilità ...xlsm"), a DUE fogli:
 *   - "Foglio di partenza" (orizzontale): una riga per lotto di produzione — timestamp, lotto,
 *     poi i componenti come `lotto*quantità` (decimale con virgola);
 *   - "Nuovo" (lungo): intestazione + una riga per componente (Informazioni cronologiche, Lotto,
 *     LE=lotto componente, Quantità (numero), Note, Operatore).
 * L'output e' pronto per {@see \App\Support\XlsxWriter::scrivi()}.
 */
final class OmniExport
{
    /**
     * @param  list<array<string,mixed>>  $produzioni  Da TracciabilitaService::albero()['produzioni'].
     * @param  array<string,mixed>  $opzioni
     * @return list<array{name:string, rows:list<list<string|int|float|null>>}>
     */
    public static function fogli(array $produzioni, array $opzioni = []): array
    {
        $operatore = (string) ($opzioni['operatore'] ?? '');

        $orizzontale = [];
        $lungo = [['Informazioni cronologiche', 'Inserisci il Lotto di Produzione', 'LE', 'Quantità', 'Note', 'Operatore']];

        foreach ($produzioni as $p) {
            $ts = (string) ($p['data'] ?? '');
            $lotto = (string) ($p['lotto'] ?? '');

            $riga = [$ts, $lotto];
            foreach ((array) ($p['componenti'] ?? []) as $c) {
                $lottoComp = trim((string) ($c['lotto'] ?? ''));
                if ($lottoComp === '') {
                    continue;
                }
                $q = (float) ($c['quantita'] ?? 0);
                $riga[] = $lottoComp.'*'.self::qtaVirgola($q);           // orizzontale: lotto*quantità
                $lungo[] = [$ts, $lotto, $lottoComp, $q, '', $operatore]; // lungo: quantità come numero
            }
            $orizzontale[] = $riga;
        }

        return [
            ['name' => 'Foglio di partenza', 'rows' => $orizzontale],
            ['name' => 'Nuovo', 'rows' => $lungo],
        ];
    }

    /** Quantità con virgola decimale e zeri finali rimossi (256.800000 -> "256,8", 1284 -> "1284"). */
    private static function qtaVirgola(int|float|string $valore): string
    {
        $s = rtrim(rtrim(number_format((float) $valore, 6, '.', ''), '0'), '.');

        return str_replace('.', ',', $s === '' ? '0' : $s);
    }
}
