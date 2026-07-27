<?php

declare(strict_types=1);

namespace Tests\Unit\Stock;

use App\Stock\FixtureStockAdapter;
use PHPUnit\Framework\TestCase;

/**
 * FixtureStockAdapter::lottiTuttiMagazzini (change #2): in sviluppo i fixture rappresentano il
 * mag. 06, quindi i lotti sono restituiti taggati '06', in ordine FIFO. Nessun DB.
 */
final class FixtureStockAdapterTest extends TestCase
{
    public function test_lotti_tutti_magazzini_taggati_e_ordinati_fifo(): void
    {
        $adapter = new FixtureStockAdapter([
            'X' => ['lotti' => [
                ['lotto' => 'L2', 'quantita' => 3, 'rif_fifo' => 2],
                ['lotto' => 'L1', 'quantita' => 5, 'rif_fifo' => 1],
            ]],
        ]);

        self::assertSame([
            ['magazzino' => '06', 'lotto' => 'L1', 'quantita' => 5.0],
            ['magazzino' => '06', 'lotto' => 'L2', 'quantita' => 3.0],
        ], $adapter->lottiTuttiMagazzini('X'));
    }

    public function test_articolo_senza_lotti_ritorna_vuoto(): void
    {
        self::assertSame([], (new FixtureStockAdapter([]))->lottiTuttiMagazzini('X'));
    }
}
