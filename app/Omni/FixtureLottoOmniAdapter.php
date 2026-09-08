<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;

/**
 * Adapter mappatura lotto Omni per sviluppo/test: modella in memoria i lotti Omni (una riga per lotto,
 * con articolo/lotto ESOLVER, lotto Omni, data di carico e giacenza) e applica LA STESSA regola
 * dell'adapter Access di produzione (§6-bis):
 *   1) match esatto articolo+lotto ESOLVER -> lotto Omni piu' vecchio con giacenza > 0 (FIFO);
 *   2) fallback per SOLO ARTICOLO al lotto Omni piu' vecchio con giacenza > 0.
 * In locale non c'e' il DB Access, quindi di default e' vuoto e restituisce null (il lotto resta ESOLVER).
 */
final class FixtureLottoOmniAdapter implements LottoOmniSourceInterface
{
    /**
     * @param  list<array{articolo:string, lotto_esolver:string, lotto_omni:string, data?:string, giacenza?:float|int}>  $lotti
     *                                                                                                                     Se 'giacenza' e' omessa vale 1.0 (lotto con giacenza); 'data' assente ordina per prima.
     */
    public function __construct(private readonly array $lotti = []) {}

    public function lottoOmni(string $articoloEsolver, string $lottoEsolver): ?string
    {
        $art = trim($articoloEsolver);
        $lotto = trim($lottoEsolver);
        if ($art === '' || $lotto === '') {
            return null;
        }

        // 1) esatto articolo+lotto, poi 2) fallback solo articolo.
        return $this->piuVecchioConGiacenza($art, $lotto)
            ?? $this->piuVecchioConGiacenza($art, null);
    }

    /** Lotto Omni piu' vecchio con giacenza > 0; se $lotto e' null filtra sul solo articolo. */
    private function piuVecchioConGiacenza(string $art, ?string $lotto): ?string
    {
        $candidati = array_filter($this->lotti, static function (array $l) use ($art, $lotto): bool {
            $giac = array_key_exists('giacenza', $l) ? (float) $l['giacenza'] : 1.0;

            return ($l['articolo'] ?? '') === $art
                && ($lotto === null || ($l['lotto_esolver'] ?? '') === $lotto)
                && $giac > 0;
        });

        if ($candidati === []) {
            return null;
        }

        usort($candidati, static fn (array $a, array $b) => ((string) ($a['data'] ?? '')) <=> ((string) ($b['data'] ?? '')));

        return (string) ($candidati[0]['lotto_omni'] ?? '') ?: null;
    }
}
