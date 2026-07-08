<?php

declare(strict_types=1);

namespace App\Stock;

/**
 * Un lotto disponibile sul magazzino 06 (§5.2). `rifFifo` e' il valore del campo su cui si ordina
 * il FIFO (attualmente RifLottoNum, provvisorio) — conservato per ordinamento/diagnostica.
 */
final readonly class StockLotto
{
    public function __construct(
        public string $lotto,
        public float $quantita,
        public int|string|null $rifFifo = null,
    ) {}
}
