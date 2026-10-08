<?php

declare(strict_types=1);

namespace Tests\Unit\Check;

use App\Check\CheckService;
use App\Check\FixtureArticoliAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Confronto anagrafiche ESOLVER vs Omni (§ Check): due elenchi (solo ESOLVER / solo Omni) sulla chiave
 * codice articolo, normalizzata (trim + maiuscolo) per evitare falsi disallineamenti. Logica pura.
 */
final class CheckServiceTest extends TestCase
{
    public function test_separa_gli_articoli_non_comuni(): void
    {
        $esolver = new FixtureArticoliAdapter([
            'PAN0104' => 'Panettone 1kg',
            'COL0205' => 'Colomba 750g',
            'COMUNE1' => 'Articolo comune',
        ]);
        $omni = new FixtureArticoliAdapter([
            'COMUNE1' => 'Articolo comune (Omni)',
            'TOR0300' => 'Torrone',
        ]);

        $res = (new CheckService($esolver, $omni))->confronta();

        $codiciSoloEsolver = array_column($res['soloEsolver'], 'codice');
        $codiciSoloOmni = array_column($res['soloOmni'], 'codice');

        self::assertSame(['COL0205', 'PAN0104'], $codiciSoloEsolver); // ordinati per codice
        self::assertSame(['TOR0300'], $codiciSoloOmni);
        self::assertNotContains('COMUNE1', $codiciSoloEsolver);
        self::assertNotContains('COMUNE1', $codiciSoloOmni);
    }

    public function test_confronto_ignora_spazi_e_maiuscole(): void
    {
        $esolver = new FixtureArticoliAdapter(['pan0104 ' => 'Panettone']);
        $omni = new FixtureArticoliAdapter(['PAN0104' => 'Panettone']);

        $res = (new CheckService($esolver, $omni))->confronta();

        // Stesso articolo con case/spazi diversi: non deve finire in nessuno dei due elenchi.
        self::assertSame([], $res['soloEsolver']);
        self::assertSame([], $res['soloOmni']);
    }

    public function test_porta_la_descrizione_del_gestionale_di_origine(): void
    {
        $esolver = new FixtureArticoliAdapter(['PAN0104' => 'Panettone 1kg']);
        $omni = new FixtureArticoliAdapter(['TOR0300' => 'Torrone 250g']);

        $res = (new CheckService($esolver, $omni))->confronta();

        self::assertSame('Panettone 1kg', $res['soloEsolver'][0]['descrizione']);
        self::assertSame('Torrone 250g', $res['soloOmni'][0]['descrizione']);
    }

    public function test_solo_omni_riporta_tabella_e_id(): void
    {
        $esolver = new FixtureArticoliAdapter(['PAN0104' => 'Panettone']);
        $omni = new FixtureArticoliAdapter([
            'ANACARDOBIO' => ['descrizione' => 'Anacardo bio', 'tabella' => 'T_INGREDIENTI', 'id' => '42'],
        ]);

        $res = (new CheckService($esolver, $omni))->confronta();

        self::assertCount(1, $res['soloOmni']);
        self::assertSame('ANACARDOBIO', $res['soloOmni'][0]['codice']);
        self::assertSame('T_INGREDIENTI', $res['soloOmni'][0]['tabella']);
        self::assertSame('42', $res['soloOmni'][0]['id']);
        // Il foglio ESOLVER non porta provenienza (solo codice + descrizione).
        self::assertSame('PAN0104', $res['soloEsolver'][0]['codice']);
        self::assertArrayNotHasKey('tabella', $res['soloEsolver'][0]);
    }

    public function test_anagrafiche_identiche_nessuna_differenza(): void
    {
        $a = ['PAN0104' => 'x', 'COL0205' => 'y'];
        $res = (new CheckService(new FixtureArticoliAdapter($a), new FixtureArticoliAdapter($a)))->confronta();

        self::assertSame([], $res['soloEsolver']);
        self::assertSame([], $res['soloOmni']);
    }
}
