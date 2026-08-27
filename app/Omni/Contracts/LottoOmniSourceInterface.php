<?php

declare(strict_types=1);

namespace App\Omni\Contracts;

/**
 * Sorgente della mappatura lotto ESOLVER -> lotto Omni (§6-bis). Dato l'articolo e il lotto come li
 * conosce ESOLVER, restituisce il corrispondente lotto interno Omni (il piu' vecchio con giacenza,
 * FIFO), oppure null se non c'e' corrispondenza (es. semilavorati non presenti tra i carichi Omni).
 */
interface LottoOmniSourceInterface
{
    public function lottoOmni(string $articoloEsolver, string $lottoEsolver): ?string;
}
