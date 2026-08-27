<?php

declare(strict_types=1);

namespace App\Omni;

use App\Omni\Contracts\LottoOmniSourceInterface;

/**
 * Adapter mappatura lotto Omni per sviluppo/test: legge una mappa in memoria con chiave
 * "ARTICOLO|LOTTO" (ESOLVER) -> lotto Omni. In locale non c'e' il DB Access, quindi di default e'
 * vuoto e restituisce null (il lotto resta quello ESOLVER).
 */
final class FixtureLottoOmniAdapter implements LottoOmniSourceInterface
{
    /** @param array<string,string> $mappa  ['CODART|LOTTO' => 'lottoOmni'] */
    public function __construct(private readonly array $mappa = []) {}

    public function lottoOmni(string $articoloEsolver, string $lottoEsolver): ?string
    {
        return $this->mappa[trim($articoloEsolver).'|'.trim($lottoEsolver)] ?? null;
    }
}
