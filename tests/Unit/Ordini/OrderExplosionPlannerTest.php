<?php

declare(strict_types=1);

namespace Tests\Unit\Ordini;

use App\Bom\FixtureBomAdapter;
use App\Ordini\OrderExplosionPlanner;
use App\Ordini\Planning\PhasePlan;
use PHPUnit\Framework\TestCase;

/**
 * Test Fase 2 (§14): generazione del piano di fasi dalla distinta esplosa. Nessun database.
 * Copre i criteri di accettazione 1, 2, 5 e la normalizzazione delle quantita' pianificate.
 */
final class OrderExplosionPlannerTest extends TestCase
{
    private function pianoAsspan(float $qta = 10.0): PhasePlan
    {
        $adapter = new FixtureBomAdapter(dirname(__DIR__, 2).'/fixtures/bom');
        $planner = new OrderExplosionPlanner(6);

        return $planner->plan($adapter->explode('ASSPAN01'), $qta);
    }

    public function test_genera_una_fase_per_nodo_prodotto(): void
    {
        $piano = $this->pianoAsspan();

        self::assertSame(8, $piano->conta());
        self::assertNotNull($piano->fase('ASSPAN01'));
        self::assertNotNull($piano->fase('IMPASTOCOLOMBE/PANETTONI'));
    }

    public function test_nodo_condiviso_genera_una_sola_fase_con_quantita_sommata(): void
    {
        $piano = $this->pianoAsspan(10.0);

        // Criterio 5 / Fase 2: una sola fase per IMPASTOCOLOMBE.
        $condivise = $piano->fasiCondivise();
        self::assertCount(1, $condivise);
        self::assertSame('IMPASTOCOLOMBE/PANETTONI', $condivise[0]->articoloCodice);

        // Prodotto una volta: 3.6 + 3.6 per unita' => 72 kg per 10 unita' d'ordine.
        $imp = $piano->fase('IMPASTOCOLOMBE/PANETTONI');
        self::assertTrue($imp->isCondiviso);
        self::assertEqualsWithDelta(72.0, $imp->quantitaPianificata, 1e-6);
    }

    public function test_materiale_semilavorato_ha_quantita_di_ramo_non_totale(): void
    {
        $piano = $this->pianoAsspan(10.0);

        // Nel ramo AN/ALB si consumano 3.6/unita' di impasto base => 36 kg (meta' del totale 72).
        $trad = $piano->fase('IMPASTOTRADPIST/AN/ALB');
        $matBase = collect($trad->materiali)->firstWhere('articoloCodice', 'IMPASTOCOLOMBE/PANETTONI');

        self::assertNotNull($matBase);
        self::assertTrue($matBase->eSemilavorato);
        self::assertEqualsWithDelta(36.0, $matBase->quantitaPianificata, 1e-6);
    }

    public function test_materiali_grezzi_del_nodo_condiviso_sommano_le_righe_duplicate(): void
    {
        $piano = $this->pianoAsspan(10.0);
        $imp = $piano->fase('IMPASTOCOLOMBE/PANETTONI');

        // 17 materiali distinti; ZUCCHERO-SEM = 1.8/unita' => 18 kg (0.9 + 0.9 sommate).
        self::assertCount(17, $imp->materiali);
        $zucchero = collect($imp->materiali)->firstWhere('articoloCodice', 'ZUCCHERO-SEM');
        self::assertEqualsWithDelta(18.0, $zucchero->quantitaPianificata, 1e-6);
    }

    public function test_precedenze_bottom_up_sui_due_rami_verso_il_nodo_condiviso(): void
    {
        $piano = $this->pianoAsspan();

        // Criterio 5: entrambi i gusti dipendono dall'impasto base => sbloccheranno lo split.
        self::assertContains('IMPASTOCOLOMBE/PANETTONI', $piano->fase('IMPASTOTRADPIST/AN/ALB')->fasiFiglieCodici);
        self::assertContains('IMPASTOCOLOMBE/PANETTONI', $piano->fase('IMPASTOTRADPIST/PESC/CIOC')->fasiFiglieCodici);

        // La radice dipende dai due panettoni finiti.
        self::assertEqualsCanonicalizing(
            ['PAN0104', 'PAN0136'],
            $piano->fase('ASSPAN01')->fasiFiglieCodici,
        );
    }

    public function test_radice_ha_quantita_pari_alla_quantita_ordine(): void
    {
        $piano = $this->pianoAsspan(10.0);
        $radice = $piano->fase('ASSPAN01');

        self::assertEqualsWithDelta(10.0, $radice->quantitaPianificata, 1e-6);
        self::assertSame(0, $radice->livello);
        self::assertFalse($radice->isCondiviso);
    }

    public function test_generalita_su_pan0104(): void
    {
        $adapter = new FixtureBomAdapter(dirname(__DIR__, 2).'/fixtures/bom');
        $piano = (new OrderExplosionPlanner(6))->plan($adapter->explode('PAN0104'), 100.0);

        self::assertSame(4, $piano->conta());
        // In quest'ordine l'impasto base ha un solo padre: nessuno split.
        self::assertCount(0, $piano->fasiCondivise());
        self::assertFalse($piano->fase('IMPASTOCOLOMBE/PANETTONI')->isCondiviso);
    }
}
