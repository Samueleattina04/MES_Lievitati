<?php

declare(strict_types=1);

namespace Tests\Unit\Produzione;

use App\Produzione\Tolleranza;
use PHPUnit\Framework\TestCase;

/**
 * Test della tolleranza usata per multi-lotto (§6) e split (§5-bis).
 */
final class TolleranzaTest extends TestCase
{
    public function test_entro_tolleranza_assoluta(): void
    {
        // Esempio §6: farina 28,70 = 18,70 + 10,00 (differenza 0).
        self::assertTrue(Tolleranza::entro(28.70, 18.70 + 10.00, 0.01));

        // Entro +/-0,01.
        self::assertTrue(Tolleranza::entro(10.0, 10.009, 0.01));
        self::assertTrue(Tolleranza::entro(10.0, 9.991, 0.01));

        // Oltre tolleranza.
        self::assertFalse(Tolleranza::entro(10.0, 10.02, 0.01));
        self::assertFalse(Tolleranza::entro(72.0, 70.0, 0.01));
    }

    public function test_assorbe_errori_float(): void
    {
        self::assertTrue(Tolleranza::entro(0.3, 0.1 + 0.2, 0.0));
    }
}
