<?php

declare(strict_types=1);

namespace Tests\Unit\Omni;

use App\Omni\FixtureLottoOmniAdapter;
use App\Omni\TraduttoreLottiOmni;
use PHPUnit\Framework\TestCase;

/**
 * Traduzione lotto ESOLVER -> lotti Omni per l'export (§6-bis) con RIPARTIZIONE FIFO della quantita' su
 * piu' lotti con giacenza (niente negativi in import). Un componente puo' diventare piu' righe; i
 * componenti non mappati (es. semilavorati) restano col lotto ESOLVER. Logica pura, nessun DB.
 */
final class TraduttoreLottiOmniTest extends TestCase
{
    public function test_ripartisce_la_quantita_su_piu_lotti_fifo(): void
    {
        // 100 kg da scaricare: lotto piu' vecchio ha 40, il successivo 80 -> 40 + 60.
        $source = new FixtureLottoOmniAdapter([
            ['articolo' => 'CIOCC', 'lotto_esolver' => 'LC1', 'lotto_omni' => 'OMNI-OLD', 'data' => '2026-01-01', 'giacenza' => 40],
            ['articolo' => 'CIOCC', 'lotto_esolver' => 'LC1', 'lotto_omni' => 'OMNI-NEW', 'data' => '2026-03-01', 'giacenza' => 80],
        ]);

        $produzioni = [[
            'lotto' => 'PF-1',
            'componenti' => [
                ['articolo' => 'CIOCC', 'lotto' => 'LC1', 'quantita' => 100.0, 'um' => 'KG'],
            ],
        ]];

        $comp = (new TraduttoreLottiOmni($source))->applica($produzioni)[0]['componenti'];

        self::assertCount(2, $comp);
        self::assertSame('OMNI-OLD', $comp[0]['lotto']);
        self::assertEqualsWithDelta(40.0, (float) $comp[0]['quantita'], 1e-9);
        self::assertSame('OMNI-NEW', $comp[1]['lotto']);
        self::assertEqualsWithDelta(60.0, (float) $comp[1]['quantita'], 1e-9);
        self::assertSame('LC1', $comp[0]['lotto_esolver']);
    }

    public function test_componente_non_mappato_resta_invariato(): void
    {
        $produzioni = [[
            'lotto' => 'X',
            'componenti' => [['articolo' => 'SEMILAV', 'lotto' => '7375-23126110', 'quantita' => 10.0, 'um' => 'KG']],
        ]];

        $comp = (new TraduttoreLottiOmni(new FixtureLottoOmniAdapter()))->applica($produzioni)[0]['componenti'];

        self::assertCount(1, $comp);
        self::assertSame('7375-23126110', $comp[0]['lotto']);
        self::assertArrayNotHasKey('lotto_esolver', $comp[0]);
    }

    public function test_la_giacenza_si_scala_tra_consumi_successivi(): void
    {
        // Un solo lotto con 100 kg; due consumi da 60: il secondo trova solo 40 residui e il resto (20)
        // finisce comunque sull'unico lotto (giacenza insufficiente -> totale preservato).
        $source = new FixtureLottoOmniAdapter([
            ['articolo' => 'CIOCC', 'lotto_esolver' => 'LC1', 'lotto_omni' => 'OMNI-1', 'data' => '2026-01-01', 'giacenza' => 100],
        ]);

        $produzioni = [
            ['lotto' => 'PF-1', 'componenti' => [['articolo' => 'CIOCC', 'lotto' => 'LC1', 'quantita' => 60.0, 'um' => 'KG']]],
            ['lotto' => 'PF-2', 'componenti' => [['articolo' => 'CIOCC', 'lotto' => 'LC1', 'quantita' => 60.0, 'um' => 'KG']]],
        ];

        $out = (new TraduttoreLottiOmni($source))->applica($produzioni);

        self::assertEqualsWithDelta(60.0, (float) $out[0]['componenti'][0]['quantita'], 1e-9);
        self::assertSame('OMNI-1', $out[1]['componenti'][0]['lotto']);
        self::assertEqualsWithDelta(60.0, (float) $out[1]['componenti'][0]['quantita'], 1e-9);
    }
}
