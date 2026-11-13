<?php

declare(strict_types=1);

namespace Tests\Unit\Omni;

use App\Omni\FixtureScarichiOmniAdapter;
use PHPUnit\Framework\TestCase;

/**
 * La sorgente finta degli scarichi Omni (§6-bis) torna, per i lotti richiesti, solo quelli con
 * scarichi > 0. Nessun DB.
 */
final class FixtureScarichiOmniAdapterTest extends TestCase
{
    public function test_ritorna_solo_lotti_con_scarichi_positivi(): void
    {
        $adapter = new FixtureScarichiOmniAdapter([
            '6412-27826111' => 3,
            '7120-27826110' => 0, // scaricato 0 -> non deve comparire
        ]);

        $out = $adapter->scarichiPerProduzioni(['6412-27826111', '7120-27826110', '9999-00000000']);

        self::assertSame(['6412-27826111' => 3], $out);
    }

    public function test_default_vuoto_nessuno_scarico(): void
    {
        $adapter = new FixtureScarichiOmniAdapter();

        self::assertSame([], $adapter->scarichiPerProduzioni(['6412-27826111']));
    }

    public function test_input_vuoto(): void
    {
        $adapter = new FixtureScarichiOmniAdapter(['6412-27826111' => 2]);

        self::assertSame([], $adapter->scarichiPerProduzioni([]));
    }
}
