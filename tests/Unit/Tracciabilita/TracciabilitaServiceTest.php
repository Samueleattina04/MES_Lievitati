<?php

declare(strict_types=1);

namespace Tests\Unit\Tracciabilita;

use App\Tracciabilita\FixtureMovimentiAdapter;
use App\Tracciabilita\TracciabilitaService;
use PHPUnit\Framework\TestCase;

/**
 * Tracciabilita' (§6-bis): dal lotto del prodotto finito ricostruisce l'albero carichi/scarichi
 * risalendo la distinta dai movimenti. Logica pura, nessun DB (usa il FixtureMovimentiAdapter).
 */
final class TracciabilitaServiceTest extends TestCase
{
    private function service(): TracciabilitaService
    {
        $movimenti = [
            // Carichi (versamenti da produzione)
            ['tipo' => 'carico', 'articolo' => 'PAN', 'lotto' => 'PAN-1', 'quantita' => 10, 'um' => 'PZ', 'magazzino' => '01', 'causale' => 'Carico da produzione'],
            ['tipo' => 'carico', 'articolo' => 'IMPASTO', 'lotto' => 'IMP-1', 'quantita' => 8, 'um' => 'KG', 'magazzino' => '01', 'causale' => 'Carico da produzione'],

            // Scarichi (consumi) del prodotto finito PAN-1: un semilavorato + una materia prima
            ['tipo' => 'scarico', 'lotto_prodotto' => 'PAN-1', 'articolo_prodotto' => 'PAN', 'articolo' => 'IMPASTO', 'lotto' => 'IMP-1', 'quantita' => 8, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione PAN'],
            ['tipo' => 'scarico', 'lotto_prodotto' => 'PAN-1', 'articolo_prodotto' => 'PAN', 'articolo' => 'INCARTO', 'lotto' => 'INC-1', 'quantita' => 10, 'um' => 'PZ', 'magazzino' => '06', 'causale' => 'Consumo per produzione PAN'],

            // Scarichi del semilavorato IMP-1: due materie prime (foglie)
            ['tipo' => 'scarico', 'lotto_prodotto' => 'IMP-1', 'articolo_prodotto' => 'IMPASTO', 'articolo' => 'FARINA', 'lotto' => 'FAR-1', 'quantita' => 5, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione IMPASTO'],
            ['tipo' => 'scarico', 'lotto_prodotto' => 'IMP-1', 'articolo_prodotto' => 'IMPASTO', 'articolo' => 'BURRO', 'lotto' => 'BUR-1', 'quantita' => 2, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione IMPASTO'],
        ];

        return new TracciabilitaService(new FixtureMovimentiAdapter($movimenti));
    }

    /** @param list<array<string,mixed>> $componenti */
    private function trova(array $componenti, string $articolo): ?array
    {
        foreach ($componenti as $c) {
            if ($c['articolo'] === $articolo) {
                return $c;
            }
        }

        return null;
    }

    public function test_risale_la_distinta_dal_lotto_del_finito(): void
    {
        $res = $this->service()->albero('PAN-1');

        self::assertTrue($res['trovato']);
        self::assertSame('PAN-1', $res['nodo']['lotto']);
        self::assertSame('PAN', $res['nodo']['articolo']);
        self::assertEqualsWithDelta(10.0, (float) $res['nodo']['quantita_prodotta'], 1e-9);

        $componenti = $res['nodo']['componenti'];
        self::assertCount(2, $componenti);

        $impasto = $this->trova($componenti, 'IMPASTO');
        self::assertNotNull($impasto);
        self::assertTrue($impasto['semilavorato']);
        self::assertNotNull($impasto['figlio']);
        // Il semilavorato espande le sue due materie prime.
        self::assertCount(2, $impasto['figlio']['componenti']);
        self::assertEqualsWithDelta(8.0, (float) $impasto['figlio']['quantita_prodotta'], 1e-9);

        $incarto = $this->trova($componenti, 'INCARTO');
        self::assertNotNull($incarto);
        self::assertFalse($incarto['semilavorato']);
        self::assertNull($incarto['figlio']);
    }

    public function test_lista_piatta_contiene_tutti_i_carichi_e_scarichi(): void
    {
        $res = $this->service()->albero('PAN-1');

        // 2 carichi + 4 scarichi = 6 movimenti.
        self::assertCount(6, $res['movimenti']);
        $carichi = array_filter($res['movimenti'], static fn ($m) => $m['tipo'] === 'carico');
        $scarichi = array_filter($res['movimenti'], static fn ($m) => $m['tipo'] === 'scarico');
        self::assertCount(2, $carichi);
        self::assertCount(4, $scarichi);
    }

    public function test_lotto_inesistente_non_trovato(): void
    {
        $res = $this->service()->albero('NON-ESISTE');

        self::assertFalse($res['trovato']);
    }

    public function test_export_omni_due_fogli_orizzontale_e_lungo(): void
    {
        $res = $this->service()->albero('PAN-1');
        $fogli = \App\Tracciabilita\OmniExport::fogli($res['produzioni']);

        self::assertSame('Foglio di partenza', $fogli[0]['name']);
        self::assertSame('Nuovo', $fogli[1]['name']);

        // Foglio orizzontale: una riga per lotto, componenti come lotto*quantità (col 1 = lotto).
        $orizz = $fogli[0]['rows'];
        $rigaPan = null;
        $rigaImp = null;
        foreach ($orizz as $r) {
            if (($r[1] ?? null) === 'PAN-1') {
                $rigaPan = $r;
            }
            if (($r[1] ?? null) === 'IMP-1') {
                $rigaImp = $r;
            }
        }
        self::assertNotNull($rigaPan);
        self::assertContains('IMP-1*8', $rigaPan);
        self::assertContains('INC-1*10', $rigaPan);
        self::assertNotNull($rigaImp);
        self::assertContains('FAR-1*5', $rigaImp);
        self::assertContains('BUR-1*2', $rigaImp);

        // Foglio lungo: intestazione + una riga per componente (quantità come numero).
        $lungo = $fogli[1]['rows'];
        self::assertSame('Informazioni cronologiche', $lungo[0][0]);
        self::assertSame('LE', $lungo[0][2]);
        // Almeno una riga dettaglio con quantità numerica.
        self::assertGreaterThanOrEqual(5, count($lungo)); // header + 4 componenti
    }
}
