<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;

/**
 * Adapter mappatura lotto Omni per sviluppo/test: modella in memoria i lotti Omni (una riga per lotto,
 * con articolo/lotto ESOLVER, lotto Omni, data di carico e giacenza) e applica LA STESSA regola
 * dell'adapter Access di produzione (§6-bis): lotti con giacenza > 0 in ordine FIFO per il match esatto
 * articolo+lotto, poi in coda i lotti dello stesso articolo (fallback). In locale non c'e' il DB Access,
 * quindi di default e' vuoto e restituisce un elenco vuoto (il lotto resta quello ESOLVER).
 */
final class FixtureLottoOmniAdapter implements LottoOmniSourceInterface
{
    /**
     * @param  list<array{articolo:string, lotto_esolver:string, lotto_omni:string, data?:string, giacenza?:float|int}>  $lotti
     *                                                                                                                     Se 'giacenza' e' omessa vale 1.0; 'data' assente ordina per prima.
     */
    public function __construct(private readonly array $lotti = []) {}

    public function lottiConGiacenza(string $articoloEsolver, string $lottoEsolver): array
    {
        $art = trim($articoloEsolver);
        $lotto = trim($lottoEsolver);
        if ($art === '') {
            return [];
        }

        // 1) match esatto articolo+lotto, FIFO.
        $cand = $lotto !== '' ? $this->candidati($art, $lotto) : [];

        // 2) in coda i lotti dello stesso articolo non gia' inclusi (fallback / copertura quantita').
        $visti = [];
        foreach ($cand as $c) {
            $visti[$c['lotto']] = true;
        }
        foreach ($this->candidati($art, null) as $c) {
            if (! isset($visti[$c['lotto']])) {
                $cand[] = $c;
                $visti[$c['lotto']] = true;
            }
        }

        return $cand;
    }

    /**
     * Lotti con giacenza > 0 in ordine FIFO; se $lotto e' null filtra sul solo articolo.
     *
     * @return list<array{lotto:string,giacenza:float,data:string}>
     */
    private function candidati(string $art, ?string $lotto): array
    {
        $f = array_filter($this->lotti, static function (array $l) use ($art, $lotto): bool {
            $giac = array_key_exists('giacenza', $l) ? (float) $l['giacenza'] : 1.0;

            return ($l['articolo'] ?? '') === $art
                && ($lotto === null || ($l['lotto_esolver'] ?? '') === $lotto)
                && $giac > 0;
        });

        usort($f, static fn (array $a, array $b) => ((string) ($a['data'] ?? '')) <=> ((string) ($b['data'] ?? '')));

        return array_values(array_map(static fn (array $l) => [
            'lotto' => (string) ($l['lotto_omni'] ?? ''),
            'giacenza' => array_key_exists('giacenza', $l) ? (float) $l['giacenza'] : 1.0,
            'data' => (string) ($l['data'] ?? ''),
        ], $f));
    }
}
