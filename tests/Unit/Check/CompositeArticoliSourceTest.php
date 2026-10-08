<?php

declare(strict_types=1);

namespace Tests\Unit\Check;

use App\Check\CompositeArticoliSource;
use App\Check\FixtureArticoliAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Unione di piu' sorgenti articoli (§ Check): l'anagrafica Omni somma T_INGREDIENTI e T_PRODOTTI.
 * A parita' di codice vince la prima occorrenza; la descrizione vuota viene completata da una sorgente
 * successiva. Logica pura, nessun DB.
 */
final class CompositeArticoliSourceTest extends TestCase
{
    public function test_unisce_le_sorgenti(): void
    {
        $ingredienti = new FixtureArticoliAdapter(['ANACARDOBIO' => 'Anacardo bio', 'ZUCCHERO' => 'Zucchero']);
        $prodotti = new FixtureArticoliAdapter(['PAN0104' => 'Panettone', 'COL0205' => 'Colomba']);

        $tutti = (new CompositeArticoliSource($ingredienti, $prodotti))->articoli();

        self::assertCount(4, $tutti);
        self::assertArrayHasKey('ANACARDOBIO', $tutti);
        self::assertArrayHasKey('PAN0104', $tutti);
        self::assertSame('Panettone', $tutti['PAN0104']);
    }

    public function test_prima_occorrenza_vince_ma_completa_la_descrizione_mancante(): void
    {
        $a = new FixtureArticoliAdapter(['X1' => 'Descr A', 'X2' => '']);
        $b = new FixtureArticoliAdapter(['X1' => 'Descr B', 'X2' => 'Descr da B']);

        $tutti = (new CompositeArticoliSource($a, $b))->articoli();

        self::assertSame('Descr A', $tutti['X1']);     // prima occorrenza vince
        self::assertSame('Descr da B', $tutti['X2']);  // descrizione vuota completata dalla seconda
    }

    public function test_senza_sorgenti_elenco_vuoto(): void
    {
        self::assertSame([], (new CompositeArticoliSource())->articoli());
    }
}
