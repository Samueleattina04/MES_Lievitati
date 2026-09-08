<?php

declare(strict_types=1);

namespace Tests\Unit\Omni;

use App\Omni\FixtureLottoOmniAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Regola di selezione del lotto Omni per l'export (§6-bis), specchio della logica dell'adapter Access:
 * si propone SEMPRE un lotto Omni con giacenza reale > 0 (l'import in Omni non deve mai andare in
 * negativo). FIFO sul piu' vecchio con giacenza; se il lotto esatto e' esaurito/negativo, fallback per
 * solo articolo. Logica pura, nessun DB.
 */
final class FixtureLottoOmniAdapterTest extends TestCase
{
    public function test_prende_il_piu_vecchio_con_giacenza(): void
    {
        $a = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-NEW', 'data' => '2026-05-01', 'giacenza' => 50],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-OLD', 'data' => '2026-01-01', 'giacenza' => 20],
        ]);

        self::assertSame('OMNI-OLD', $a->lottoOmni('BURRO', 'LB1'));
    }

    public function test_salta_il_lotto_omni_senza_giacenza(): void
    {
        // Il piu' vecchio e' negativo: si passa al successivo con giacenza.
        $b = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-OLD-NEG', 'data' => '2026-01-01', 'giacenza' => -5],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-MID', 'data' => '2026-03-01', 'giacenza' => 10],
        ]);

        self::assertSame('OMNI-MID', $b->lottoOmni('BURRO', 'LB1'));
    }

    public function test_fallback_per_articolo_quando_il_lotto_e_esaurito(): void
    {
        // LB1 non ha alcun lotto Omni con giacenza -> si risale per articolo (BURRO) al piu' vecchio
        // con giacenza, anche se e' un altro lotto ESOLVER (LB2).
        $c = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'OMNI-LB1', 'data' => '2026-01-01', 'giacenza' => 0],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB2', 'lotto_omni' => 'OMNI-LB2', 'data' => '2026-02-01', 'giacenza' => 30],
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB2', 'lotto_omni' => 'OMNI-LB2-NEW', 'data' => '2026-06-01', 'giacenza' => 30],
        ]);

        self::assertSame('OMNI-LB2', $c->lottoOmni('BURRO', 'LB1'));
    }

    public function test_null_se_nessun_lotto_ha_giacenza(): void
    {
        $d = new FixtureLottoOmniAdapter([
            ['articolo' => 'BURRO', 'lotto_esolver' => 'LB1', 'lotto_omni' => 'X', 'giacenza' => 0],
        ]);

        self::assertNull($d->lottoOmni('BURRO', 'LB1'));
    }

    public function test_giacenza_omessa_e_selezionabile(): void
    {
        $e = new FixtureLottoOmniAdapter([
            ['articolo' => 'A', 'lotto_esolver' => 'L', 'lotto_omni' => 'OM'],
        ]);

        self::assertSame('OM', $e->lottoOmni('A', 'L'));
    }
}
