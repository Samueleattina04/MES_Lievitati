<?php

declare(strict_types=1);

namespace App\Tracciabilita;

/**
 * Costruisce il foglio per l'importazione in Omni (§6-bis), nel formato reale aziendale (foglio
 * "Nuovo" del file "Tracciabilità ...xlsm"): intestazione + una riga per componente
 * (Informazioni cronologiche, Lotto di Produzione, LE=lotto componente, Quantità (numero), Note,
 * Operatore). L'output e' pronto per {@see \App\Support\XlsxWriter::scrivi()}.
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

        $righe = [['Informazioni cronologiche', 'Inserisci il Lotto di Produzione', 'LE', 'Quantità', 'Note', 'Operatore']];

        foreach ($produzioni as $p) {
            $ts = (string) ($p['data'] ?? '');
            $lotto = (string) ($p['lotto'] ?? '');
            foreach ((array) ($p['componenti'] ?? []) as $c) {
                $lottoComp = trim((string) ($c['lotto'] ?? ''));
                if ($lottoComp === '') {
                    continue;
                }
                $righe[] = [$ts, $lotto, $lottoComp, (float) ($c['quantita'] ?? 0), '', $operatore];
            }
        }

        return [
            ['name' => 'Nuovo', 'rows' => $righe],
        ];
    }
}
