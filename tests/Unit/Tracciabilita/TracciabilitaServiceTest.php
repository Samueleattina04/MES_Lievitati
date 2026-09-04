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

    public function test_export_omni_solo_foglio_nuovo_una_riga_per_componente(): void
    {
        $res = $this->service()->albero('PAN-1');
        $fogli = \App\Tracciabilita\OmniExport::fogli($res['produzioni']);

        // Un solo foglio, "Nuovo" (il "Foglio di partenza" orizzontale non serve più).
        self::assertCount(1, $fogli);
        self::assertSame('Nuovo', $fogli[0]['name']);

        $righe = $fogli[0]['rows'];
        // Intestazione.
        self::assertSame('Informazioni cronologiche', $righe[0][0]);
        self::assertSame('LE', $righe[0][2]);

        // Una riga per componente: [ts, lottoProdotto, lottoComponente, quantità(numero), note, operatore].
        // Cerca la riga PAN-1 -> IMP-1 (qtà 8) e IMP-1 -> FAR-1 (qtà 5).
        $trovaComp = static function (array $righe, string $lottoProdotto, string $lottoComp): ?array {
            foreach ($righe as $r) {
                if (($r[1] ?? null) === $lottoProdotto && ($r[2] ?? null) === $lottoComp) {
                    return $r;
                }
            }

            return null;
        };
        $r1 = $trovaComp($righe, 'PAN-1', 'IMP-1');
        self::assertNotNull($r1);
        self::assertEqualsWithDelta(8.0, (float) $r1[3], 1e-9);
        self::assertNotNull($trovaComp($righe, 'IMP-1', 'FAR-1'));

        // header + 4 componenti (IMPASTO, INCARTO, FARINA, BURRO).
        self::assertCount(5, $righe);
    }
}
