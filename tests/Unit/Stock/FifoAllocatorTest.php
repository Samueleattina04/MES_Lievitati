<?php

declare(strict_types=1);

namespace Tests\Unit\Stock;

use App\Stock\FifoAllocator;
use App\Stock\StockLotto;
use PHPUnit\Framework\TestCase;

/**
 * Test della proposta lotti FIFO (§5.2): copertura della quantita' richiesta consumando prima i
 * lotti piu' vecchi, spill su piu' lotti, proposta parziale se la giacenza non basta. Nessun DB.
 */
final class FifoAllocatorTest extends TestCase
{
    /** @param array<int,array{0:string,1:float,2:int}> $lotti */
    private function lotti(array $lotti): array
    {
        return array_map(fn ($l) => new StockLotto($l[0], $l[1], $l[2]), $lotti);
    }

    public function test_copre_con_un_solo_lotto_se_sufficiente(): void
    {
        $p = FifoAllocator::proponi($this->lotti([['L1', 10.0, 1]]), 4.0);

        self::assertCount(1, $p);
        self::assertSame('L1', $p[0]['lotto']);
        self::assertEqualsWithDelta(4.0, $p[0]['quantita'], 1e-9);
    }

    public function test_spill_su_piu_lotti_in_ordine_fifo(): void
    {
        // Farina 28,70 = 18,70 (L1) + 10,00 (L2) — esempio reale §5.2.
        $p = FifoAllocator::proponi($this->lotti([
            ['L1', 18.7, 1],
            ['L2', 50.0, 2],
        ]), 28.7);

        self::assertCount(2, $p);
        self::assertSame('L1', $p[0]['lotto']);
        self::assertEqualsWithDelta(18.7, $p[0]['quantita'], 1e-9);
        self::assertSame('L2', $p[1]['lotto']);
        self::assertEqualsWithDelta(10.0, $p[1]['quantita'], 1e-9);
        self::assertEqualsWithDelta(28.7, FifoAllocator::totale($p), 1e-9);
    }

    public function test_attraversa_tre_lotti(): void
    {
        $p = FifoAllocator::proponi($this->lotti([
            ['L1', 5.0, 1], ['L2', 5.0, 2], ['L3', 5.0, 3],
        ]), 12.0);

        self::assertCount(3, $p);
        self::assertEqualsWithDelta(5.0, $p[0]['quantita'], 1e-9);
        self::assertEqualsWithDelta(5.0, $p[1]['quantita'], 1e-9);
        self::assertEqualsWithDelta(2.0, $p[2]['quantita'], 1e-9);
    }

    public function test_proposta_parziale_se_giacenza_insufficiente(): void
    {
        $p = FifoAllocator::proponi($this->lotti([['L1', 5.0, 1]]), 12.0);

        self::assertCount(1, $p);
        self::assertEqualsWithDelta(5.0, FifoAllocator::totale($p), 1e-9);
    }

    public function test_nessun_lotto_disponibile(): void
    {
        self::assertSame([], FifoAllocator::proponi([], 10.0));
    }
}
