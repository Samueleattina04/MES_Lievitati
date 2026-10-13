<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;

/**
 * Traduce i lotti dei componenti (ESOLVER) in lotti Omni per l'export Omni (§6-bis), RIPARTENDO la
 * quantita' su piu' lotti con giacenza (FIFO) cosi' l'import in Omni non va mai in negativo. Per ogni
 * componente: cerca i lotti Omni con giacenza (match esatto articolo+lotto, poi fallback per articolo) e
 * scarica dai piu' vecchi, passando al successivo quando uno non basta. Un componente puo' quindi
 * generare PIU' righe (una per lotto Omni usato). La giacenza si consuma lungo tutto l'export (pool
 * condiviso), cosi' consumi successivi non ri-usano capacita' gia' impegnata. I componenti senza
 * corrispondenza (es. semilavorati) restano col lotto ESOLVER invariato.
 */
final class TraduttoreLottiOmni
{
    /** Precisione quantita' (decimal(18,6) sorgente). */
    private const DECIMALI = 6;

    public function __construct(
        private readonly LottoOmniSourceInterface $source,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $produzioni
     * @return list<array<string,mixed>>
     */
    public function applica(array $produzioni): array
    {
        // Pool giacenza residua per lotto Omni, condiviso in tutto l'export (si scala man mano).
        /** @var array<string,float> $residuaPerLotto */
        $residuaPerLotto = [];

        foreach ($produzioni as &$p) {
            $nuoviComponenti = [];
            foreach ((array) ($p['componenti'] ?? []) as $c) {
                foreach ($this->traduciComponente((array) $c, $residuaPerLotto) as $riga) {
                    $nuoviComponenti[] = $riga;
                }
            }
            $p['componenti'] = $nuoviComponenti;
        }
        unset($p);

        return $produzioni;
    }

    /**
     * Traduce un singolo componente in una o piu' righe (una per lotto Omni usato). Se non c'e'
     * corrispondenza, restituisce il componente invariato.
     *
     * @param  array<string,mixed>  $c
     * @param  array<string,float>  $residuaPerLotto
     * @return list<array<string,mixed>>
     */
    private function traduciComponente(array $c, array &$residuaPerLotto): array
    {
        $articolo = (string) ($c['articolo'] ?? '');
        $lottoEsolver = (string) ($c['lotto'] ?? '');
        $lotti = $this->source->lottiConGiacenza($articolo, $lottoEsolver);

        if ($lotti === []) {
            return [$c]; // nessuna corrispondenza Omni: resta il lotto ESOLVER
        }

        $residuo = round((float) ($c['quantita'] ?? 0), self::DECIMALI);
        $allocazioni = [];

        foreach ($lotti as $l) {
            if ($residuo <= 0) {
                break;
            }
            $lotto = $l['lotto'];
            if (! array_key_exists($lotto, $residuaPerLotto)) {
                $residuaPerLotto[$lotto] = (float) $l['giacenza'];
            }
            $disponibile = $residuaPerLotto[$lotto];
            if ($disponibile <= 0) {
                continue;
            }
            $prendi = round(min($disponibile, $residuo), self::DECIMALI);
            if ($prendi <= 0) {
                continue;
            }
            $residuaPerLotto[$lotto] = round($disponibile - $prendi, self::DECIMALI);
            $allocazioni[] = ['lotto' => $lotto, 'quantita' => $prendi];
            $residuo = round($residuo - $prendi, self::DECIMALI);
        }

        // Giacenza insufficiente: il resto va comunque su un lotto Omni valido (totale preservato).
        // E' l'unico caso che puo' generare un negativo, e segnala una reale mancanza di giacenza.
        if ($residuo > 0) {
            if ($allocazioni !== []) {
                $allocazioni[count($allocazioni) - 1]['quantita'] = round($allocazioni[count($allocazioni) - 1]['quantita'] + $residuo, self::DECIMALI);
            } else {
                $lotto = $lotti[0]['lotto'];
                $residuaPerLotto[$lotto] = round(($residuaPerLotto[$lotto] ?? (float) $lotti[0]['giacenza']) - $residuo, self::DECIMALI);
                $allocazioni[] = ['lotto' => $lotto, 'quantita' => $residuo];
            }
        }

        $out = [];
        foreach ($allocazioni as $a) {
            $riga = $c;
            $riga['lotto_esolver'] = $lottoEsolver;
            $riga['lotto'] = $a['lotto'];
            $riga['quantita'] = $a['quantita'];
            $out[] = $riga;
        }

        return $out;
    }
}
