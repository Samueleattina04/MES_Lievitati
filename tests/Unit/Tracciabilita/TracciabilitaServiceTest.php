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

    /**
     * Anti-esplosione (caso reale lotto 6412-27826111): i componenti con lotto "degenere" (numeri nudi
     * come "1", date come "10/12/2025") NON vanno espansi — farlo userebbe quei valori come jolly su
     * milioni di movimenti e manderebbe in timeout. Restano foglie; i semilavorati veri si espandono.
     */
    public function test_non_espande_i_lotti_degeneri(): void
    {
        $movimenti = [
            ['tipo' => 'carico', 'articolo' => 'PANFINITO', 'lotto' => '6412-27826111', 'quantita' => 10, 'um' => 'PZ', 'magazzino' => '01', 'causale' => 'Carico da produzione'],
            // Consumi del finito: un semilavorato vero (col trattino) + due componenti con lotto degenere.
            ['tipo' => 'scarico', 'lotto_prodotto' => '6412-27826111', 'articolo_prodotto' => 'PANFINITO', 'articolo' => 'PANARANCIAROSSA/FOND750', 'lotto' => '1113-25326110', 'quantita' => 96, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione'],
            ['tipo' => 'scarico', 'lotto_prodotto' => '6412-27826111', 'articolo_prodotto' => 'PANFINITO', 'articolo' => 'BASEPANVINCENTE-750', 'lotto' => '1', 'quantita' => 72, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione'],
            ['tipo' => 'scarico', 'lotto_prodotto' => '6412-27826111', 'articolo_prodotto' => 'PANFINITO', 'articolo' => 'BASEPANVINCENTE-750', 'lotto' => '10/12/2025', 'quantita' => 24, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione'],
            // TRAPPOLA: il lotto degenere "1" ha "figli" che NON devono comparire (non va espanso).
            ['tipo' => 'scarico', 'lotto_prodotto' => '1', 'articolo_prodotto' => 'JOLLY', 'articolo' => 'NON-DEVE-COMPARIRE', 'lotto' => '777-77777777', 'quantita' => 1, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'jolly'],
            // Il semilavorato vero 1113-25326110 ha una materia prima foglia (deve comparire = espanso).
            ['tipo' => 'scarico', 'lotto_prodotto' => '1113-25326110', 'articolo_prodotto' => 'PANARANCIAROSSA/FOND750', 'articolo' => 'FARINA', 'lotto' => 'L120618300', 'quantita' => 50, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo per produzione'],
        ];

        $res = (new TracciabilitaService(new FixtureMovimentiAdapter($movimenti)))->albero('6412-27826111');

        self::assertTrue($res['trovato']);
        $articoli = array_map(static fn ($m) => $m['articolo'], $res['movimenti']);

        // Il semilavorato vero e' stato espanso (compare la sua materia prima).
        self::assertContains('FARINA', $articoli);
        // I componenti con lotto degenere restano foglie (compaiono come consumo del finito)...
        self::assertContains('BASEPANVINCENTE-750', $articoli);
        // ...ma NON sono stati espansi: i loro "figli" non compaiono.
        self::assertNotContains('NON-DEVE-COMPARIRE', $articoli);

        // Nell'albero: il componente col lotto "1" e' foglia; il semilavorato vero ha figlio.
        $componenti = $res['nodo']['componenti'];
        $base = $this->trova($componenti, 'BASEPANVINCENTE-750');
        self::assertNotNull($base);
        self::assertFalse($base['semilavorato']);
        self::assertNull($base['figlio']);

        $semi = $this->trova($componenti, 'PANARANCIAROSSA/FOND750');
        self::assertNotNull($semi);
        self::assertNotNull($semi['figlio']);
    }

    public function test_proporziona_le_materie_dellimpasto_per_la_quota_usata(): void
    {
        $movimenti = [
            // Finito 100-1 usa 0,25 dell'impasto 200-1.
            ['tipo' => 'carico', 'articolo' => 'PANETTONE', 'lotto' => '100-1', 'quantita' => 10, 'um' => 'PZ', 'magazzino' => '01', 'causale' => 'Carico da produzione'],
            ['tipo' => 'scarico', 'lotto_prodotto' => '100-1', 'articolo_prodotto' => 'PANETTONE', 'articolo' => 'IMPASTO', 'lotto' => '200-1', 'quantita' => 0.25, 'um' => 'NR', 'magazzino' => '06', 'causale' => 'Consumo'],
            // Impasto 200-1 prodotto come 1 intero, con 100 kg di farina (dell'intero batch).
            ['tipo' => 'carico', 'articolo' => 'IMPASTO', 'lotto' => '200-1', 'quantita' => 1, 'um' => 'NR', 'magazzino' => '01', 'causale' => 'Carico da produzione'],
            ['tipo' => 'scarico', 'lotto_prodotto' => '200-1', 'articolo_prodotto' => 'IMPASTO', 'articolo' => 'FARINA', 'lotto' => 'FAR9', 'quantita' => 100, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo'],
        ];

        $res = (new TracciabilitaService(new FixtureMovimentiAdapter($movimenti)))->albero('100-1');
        $righe = \App\Tracciabilita\OmniExport::fogli($res['produzioni'])[0]['rows'];

        $trova = static function (array $righe, string $prod, string $comp): ?array {
            foreach ($righe as $r) {
                if (($r[1] ?? null) === $prod && ($r[2] ?? null) === $comp) {
                    return $r;
                }
            }

            return null;
        };

        // Il finito ha usato 0,25 di impasto: riga invariata.
        $rImp = $trova($righe, '100-1', '200-1');
        self::assertNotNull($rImp);
        self::assertEqualsWithDelta(0.25, (float) $rImp[3], 1e-9);

        // La farina dell'impasto e' scaricata al 25%: 100 * 0,25 = 25 (NON 100).
        $rFar = $trova($righe, '200-1', 'FAR9');
        self::assertNotNull($rFar);
        self::assertEqualsWithDelta(25.0, (float) $rFar[3], 1e-9);
    }

    /**
     * Caso reale (impasto 3 cioccolati): un semilavorato (GLASSA) consumato DUE volte dallo stesso
     * finito con quantita' diverse (5 KG e 155 KG, batch 160). Le sue materie prime devono essere
     * proporzionate DIVERSAMENTE per ogni ramo (5/160 e 155/160), non con lo stesso fattore; nell'export
     * la glassa (consumata per intero: 5+155=160) deve avere le materie al 100%.
     */
    public function test_proporziona_per_occorrenza_semilavorato_consumato_due_volte(): void
    {
        $movimenti = [
            ['tipo' => 'carico', 'articolo' => 'IMP3', 'lotto' => '7364-1', 'quantita' => 5, 'um' => 'PZ', 'magazzino' => '01', 'causale' => 'Carico da produzione'],
            // L'impasto consuma la GLASSA due volte: 5 KG e 155 KG (stesso lotto, prodotto 160 KG).
            ['tipo' => 'scarico', 'lotto_prodotto' => '7364-1', 'articolo_prodotto' => 'IMP3', 'articolo' => 'GLASSA', 'lotto' => '7372-1', 'quantita' => 5, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo'],
            ['tipo' => 'scarico', 'lotto_prodotto' => '7364-1', 'articolo_prodotto' => 'IMP3', 'articolo' => 'GLASSA', 'lotto' => '7372-1', 'quantita' => 155, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo'],
            ['tipo' => 'carico', 'articolo' => 'GLASSA', 'lotto' => '7372-1', 'quantita' => 160, 'um' => 'KG', 'magazzino' => '01', 'causale' => 'Carico da produzione'],
            ['tipo' => 'scarico', 'lotto_prodotto' => '7372-1', 'articolo_prodotto' => 'GLASSA', 'articolo' => 'ALBUME', 'lotto' => 'ALB1', 'quantita' => 54.44, 'um' => 'KG', 'magazzino' => '06', 'causale' => 'Consumo'],
        ];

        $res = (new TracciabilitaService(new FixtureMovimentiAdapter($movimenti)))->albero('7364-1');

        // Albero: due rami GLASSA (5 e 155), materie proporzionate DIVERSAMENTE.
        $ramo5 = $ramo155 = null;
        foreach ($res['nodo']['componenti'] as $c) {
            if ($c['articolo'] !== 'GLASSA') {
                continue;
            }
            if (abs((float) $c['quantita'] - 5.0) < 1e-6) {
                $ramo5 = $c;
            }
            if (abs((float) $c['quantita'] - 155.0) < 1e-6) {
                $ramo155 = $c;
            }
        }
        self::assertNotNull($ramo5);
        self::assertNotNull($ramo155);
        // 5 KG: albume 54,44 * 5/160 = 1,701.
        self::assertEqualsWithDelta(1.701, (float) $this->trova($ramo5['figlio']['componenti'], 'ALBUME')['quantita'], 1e-3);
        // 155 KG: albume 54,44 * 155/160 = 52,739 (NON 1,701 come prima del fix).
        self::assertEqualsWithDelta(52.739, (float) $this->trova($ramo155['figlio']['componenti'], 'ALBUME')['quantita'], 1e-3);

        // Export: una riga per la glassa, albume TOTALE = 54,44 (5/160 + 155/160 = intero).
        $righe = \App\Tracciabilita\OmniExport::fogli($res['produzioni'])[0]['rows'];
        $rAlb = null;
        foreach ($righe as $r) {
            if (($r[1] ?? null) === '7372-1' && ($r[2] ?? null) === 'ALB1') {
                $rAlb = $r;
            }
        }
        self::assertNotNull($rAlb);
        self::assertEqualsWithDelta(54.44, (float) $rAlb[3], 1e-2);
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
