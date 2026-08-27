<?php

declare(strict_types=1);

namespace Tests\Unit\Omni;

use App\Omni\FixtureLottoOmniAdapter;
use App\Omni\TraduttoreLottiOmni;
use PHPUnit\Framework\TestCase;

/**
 * Traduzione lotto ESOLVER -> lotto Omni per l'export (§6-bis): i componenti mappati assumono il lotto
 * Omni, quelli non mappati (es. semilavorati) restano col lotto ESOLVER. Logica pura, nessun DB.
 */
final class TraduttoreLottiOmniTest extends TestCase
{
    public function test_sostituisce_solo_i_lotti_mappati(): void
    {
        $source = new FixtureLottoOmniAdapter([
            'PT0LI25|3126004810' => '2748.21726.819',       // farina: mappata
            'ZUCCHEROSEMOLAT|L120618300' => '1500.20026.42', // zucchero: mappata
        ]);
        $traduttore = new TraduttoreLottiOmni($source);

        $produzioni = [[
            'lotto' => '7362-23326110',
            'componenti' => [
                ['articolo' => 'PT0LI25', 'lotto' => '3126004810', 'quantita' => 128.0, 'um' => 'KG'],
                ['articolo' => 'ZUCCHEROSEMOLAT', 'lotto' => 'L120618300', 'quantita' => 64.0, 'um' => 'KG'],
                ['articolo' => 'IMPASTOBASE', 'lotto' => '7375-23126110', 'quantita' => 10.0, 'um' => 'KG'], // semilavorato: non mappato
            ],
        ]];

        $out = $traduttore->applica($produzioni);
        $comp = $out[0]['componenti'];

        // Materie prime: lotto tradotto in lotto Omni, con l'originale conservato.
        self::assertSame('2748.21726.819', $comp[0]['lotto']);
        self::assertSame('3126004810', $comp[0]['lotto_esolver']);
        self::assertSame('1500.20026.42', $comp[1]['lotto']);

        // Semilavorato non mappato: lotto invariato.
        self::assertSame('7375-23126110', $comp[2]['lotto']);
        self::assertArrayNotHasKey('lotto_esolver', $comp[2]);
    }

    public function test_nessuna_mappatura_lascia_tutto_invariato(): void
    {
        $traduttore = new TraduttoreLottiOmni(new FixtureLottoOmniAdapter());

        $produzioni = [[
            'lotto' => 'X',
            'componenti' => [['articolo' => 'A', 'lotto' => 'L1', 'quantita' => 1.0, 'um' => 'KG']],
        ]];

        $out = $traduttore->applica($produzioni);
        self::assertSame('L1', $out[0]['componenti'][0]['lotto']);
    }
}
