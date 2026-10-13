<?php

declare(strict_types=1);

namespace Tests\Unit\Omni;

use App\Omni\FixtureLottoOmniAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Elenco dei lotti Omni con giacenza (§6-bis), specchio dell'adapter Access: solo lotti con giacenza > 0,
 * in ordine FIFO per il match esatto articolo+lotto, poi in coda i lotti dello stesso articolo (fallback).
 * Serve al traduttore per ripartire la quantita' su piu' lotti. Logica pura, nessun DB.
 */
final class FixtureLottoOmniAdapterTest extends TestCase
{
    public function test_elenca_i_lotti_con_giacenza_in_ordine_fifo(): void
    {
        $a = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-NEW', 'data' => '2026-05-01', 'giacenza' => 50],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-OLD', 'data' => '2026-01-01', 'giacenza' => 20],
        ]);

        $res = $a->lottiConGiacenza('BURRO', 'LB1');

        self::assertSame(['OMNI-OLD', 'OMNI-NEW'], array_column($res, 'lotto')); // piu' vecchio prima
        self::assertEqualsWithDelta(20.0, (float) $res[0]['giacenza'], 1e-9);
    }

    public function test_esclude_i_lotti_senza_giacenza(): void
    {
        $b = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-NEG', 'data' => '2026-01-01', 'giacenza' => -5],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-OK', 'data' => '2026-03-01', 'giacenza' => 10],
        ]);

        self::assertSame(['OMNI-OK'], array_column($b->lottiConGiacenza('BURRO', 'LB1'), 'lotto'));
    }

    public function test_lotti_dello_stesso_articolo_in_coda_come_fallback(): void
    {
        $c = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-LB1', 'data' => '2026-02-01', 'giacenza' => 30],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB2', 'lotto_omni' => 'OMNI-LB2', 'data' => '2026-01-01', 'giacenza' => 40],
        ]);

        // Match esatto (LB1) prima, poi in coda gli altri lotti dell'articolo — anche se piu' vecchi.
        self::assertSame(['OMNI-LB1', 'OMNI-LB2'], array_column($c->lottiConGiacenza('BURRO', 'LB1'), 'lotto'));
    }

    public function test_nessun_candidato_elenco_vuoto(): void
    {
        $d = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'X', 'giacenza' => 0],
        ]);

        self::assertSame([], $d->lottiConGiacenza('BURRO', 'LB1'));
    }
}
