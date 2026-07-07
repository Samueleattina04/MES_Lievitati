<?php

declare(strict_types=1);

namespace Tests\Unit\Bom;

use App\Bom\BomRow;
use App\Bom\FixtureBomAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Test Fase 1 (§14): esplosione distinta via FixtureBomAdapter. Non tocca il database.
 * Valida i criteri di accettazione 1, 3, 5 e la normalizzazione quantita' (§15) sul caso ASSPAN01,
 * piu' la generalita' su un secondo articolo (PAN0104).
 */
final class BomExplosionTest extends TestCase
{
    private FixtureBomAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new FixtureBomAdapter(dirname(__DIR__, 2).'/fixtures/bom');
    }

    public function test_asspan01_genera_otto_nodi_prodotti(): void
    {
        $nodi = $this->adapter->explode('ASSPAN01')->codiciNodiProdotti();

        self::assertCount(8, $nodi, 'ASSPAN01 deve generare 8 fasi (una per nodo prodotto).');
        self::assertContains('ASSPAN01', $nodi);
        self::assertContains('IMPASTOCOLOMBE/PANETTONI', $nodi);
        self::assertContains('PAN0104', $nodi);
        self::assertContains('PAN0136', $nodi);
    }

    public function test_impastocolombe_genera_una_sola_fase_ed_e_condiviso(): void
    {
        $e = $this->adapter->explode('ASSPAN01');

        // Criterio 5 / Fase 2: il nodo condiviso genera UNA sola fase, non due.
        $occorrenzeComeNodoDistinto = collect($e->codiciNodiProdotti())
            ->filter(fn (string $c) => $c === 'IMPASTOCOLOMBE/PANETTONI')
            ->count();
        self::assertSame(1, $occorrenzeComeNodoDistinto);

        // ...ma e' consumato da due padri distinti => e' un punto di split.
        self::assertTrue($e->eCondiviso('IMPASTOCOLOMBE/PANETTONI'));
        self::assertEqualsCanonicalizing(
            ['IMPASTOTRADPIST/AN/ALB', 'IMPASTOTRADPIST/PESC/CIOC'],
            $e->padriDistinti('IMPASTOCOLOMBE/PANETTONI'),
        );
    }

    public function test_normalizzazione_quantita_nodo_condiviso(): void
    {
        $e = $this->adapter->explode('ASSPAN01');

        // Prodotto una volta sola: qta totale = somma sulle occorrenze (3.6 + 3.6 = 7.2 per unita').
        $totalePerUnita = $e->occorrenze('IMPASTOCOLOMBE/PANETTONI')->sum(fn (BomRow $r) => $r->qtaPerUnita);
        self::assertEqualsWithDelta(7.2, $totalePerUnita, 1e-9);

        // Materiali del nodo: le righe duplicate per percorso devono sommare correttamente.
        $zucchero = $e->figliDiretti('IMPASTOCOLOMBE/PANETTONI')
            ->filter(fn (BomRow $r) => $r->articolo === 'ZUCCHERO-SEM')
            ->sum(fn (BomRow $r) => $r->qtaPerUnita);
        self::assertEqualsWithDelta(1.8, $zucchero, 1e-9);
    }

    public function test_stesso_articolo_a_livelli_diversi(): void
    {
        // Caso §3: la farina PT0LI25 compare a livelli diversi nella stessa distinta.
        $livelli = $this->adapter->explode('ASSPAN01')
            ->occorrenze('PT0LI25')
            ->map(fn (BomRow $r) => $r->livello)
            ->unique()
            ->sort()
            ->values()
            ->all();

        self::assertSame([4, 5], $livelli);
    }

    public function test_articolo_con_quantita_zero_e_ripetuto(): void
    {
        // Caso limite §3: BURRO-P presente in piu' fasi, con quantita' 0 e valore.
        $burro = $this->adapter->explode('ASSPAN01')->righe()
            ->filter(fn (BomRow $r) => $r->articolo === 'BURRO-P')
            ->map(fn (BomRow $r) => $r->qtaPerUnita)
            ->all();

        self::assertContains(0.0, $burro);
        self::assertTrue(collect($burro)->contains(fn ($q) => $q > 0));
    }

    public function test_generalita_su_secondo_articolo_pan0104(): void
    {
        $e = $this->adapter->explode('PAN0104');

        // Meno nodi (sotto-albero di un solo gusto).
        self::assertCount(4, $e->codiciNodiProdotti());

        // Lo stesso semilavorato NON e' condiviso in questo ordine (un solo padre).
        self::assertFalse($e->eCondiviso('IMPASTOCOLOMBE/PANETTONI'));

        // Criterio 3: la profondita' e' relativa al prodotto finito. In PAN0104 IMPASTOCOLOMBE
        // sta al livello 3, in ASSPAN01 al livello 4: la fase funziona senza riconfigurazione.
        self::assertSame(3, $e->livelloMassimo('IMPASTOCOLOMBE/PANETTONI'));
        self::assertSame(4, $this->adapter->explode('ASSPAN01')->livelloMassimo('IMPASTOCOLOMBE/PANETTONI'));
    }

    public function test_esiste_articolo_e_ricerca(): void
    {
        self::assertTrue($this->adapter->esisteArticolo('ASSPAN01'));
        self::assertFalse($this->adapter->esisteArticolo('NON_ESISTE_XYZ'));

        $risultati = $this->adapter->cercaArticoli('PAN');
        $codici = array_column($risultati, 'codice');
        self::assertContains('PAN0104', $codici);
    }

    public function test_protezione_path_traversal_nella_ricerca_fixture(): void
    {
        // Un codice con separatori di percorso non deve risolvere fuori dalla cartella fixture.
        self::assertFalse($this->adapter->esisteArticolo('../ASSPAN01'));
        self::assertFalse($this->adapter->esisteArticolo('..\\..\\.env'));
    }
}
