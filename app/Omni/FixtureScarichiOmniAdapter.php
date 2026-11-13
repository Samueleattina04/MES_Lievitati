<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\ScarichiOmniSourceInterface;

/**
 * Sorgente FINTA degli scarichi Omni per sviluppo/CI/test (§6-bis): nessun DB. Tiene una mappa
 * lottoProduzione => numero scarichi gia' presenti; restituisce solo i lotti richiesti con conteggio > 0.
 */
final class FixtureScarichiOmniAdapter implements ScarichiOmniSourceInterface
{
    /** @var array<string,int> */
    private array $scarichi;

    /** @param array<string,int>|null $scarichi mappa lottoProduzione => numero scarichi (default: vuota) */
    public function __construct(?array $scarichi = null)
    {
        $this->scarichi = $scarichi ?? [];
    }

    public function scarichiPerProduzioni(array $lottiProduzione): array
    {
        $out = [];
        foreach ($lottiProduzione as $l) {
            $l = trim((string) $l);
            if ($l !== '' && ($this->scarichi[$l] ?? 0) > 0) {
                $out[$l] = (int) $this->scarichi[$l];
            }
        }

        return $out;
    }
}
