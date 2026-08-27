<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;

/**
 * Traduce i lotti dei componenti (ESOLVER) in lotti Omni per l'export Omni (§6-bis). Per ogni
 * componente delle produzioni cerca il lotto Omni corrispondente: se trovato, sostituisce il lotto
 * (conservando l'originale in `lotto_esolver`); se non trovato (es. semilavorati), lascia il lotto
 * ESOLVER invariato.
 */
final class TraduttoreLottiOmni
{
    public function __construct(
        private readonly LottoOmniSourceInterface $source,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $produzioni
     * @return list<array<string,mixed>>
     */
    public function applica(array $produzioni): array
    {
        foreach ($produzioni as &$p) {
            $componenti = (array) ($p['componenti'] ?? []);
            foreach ($componenti as &$c) {
                $omni = $this->source->lottoOmni((string) ($c['articolo'] ?? ''), (string) ($c['lotto'] ?? ''));
                if ($omni !== null && $omni !== '') {
                    $c['lotto_esolver'] = $c['lotto'] ?? '';
                    $c['lotto'] = $omni;
                }
            }
            unset($c);
            $p['componenti'] = $componenti;
        }
        unset($p);

        return $produzioni;
    }
}
