<?php

declare(strict_types=1);

namespace Tests\Unit\CarichiProduzione;

use App\CarichiProduzione\CaricoProduzione;
use App\CarichiProduzione\FixtureCarichiProduzioneAdapter;
use PHPUnit\Framework\TestCase;

/**
 * La sorgente finta dei carichi (§ Carichi) applica gli stessi filtri dell'adapter reale: operatore
 * creatore (case-insensitive) e CodOpMag. Nessun DB.
 */
final class FixtureCarichiProduzioneAdapterTest extends TestCase
{
    /** @return list<CaricoProduzione> */
    private function dati(): array
    {
        return [
            new CaricoProduzione(data: '01/11/2026 08.00.00', articolo: 'A', descrizione: '', lotto: 'LA', quantita: 1, um: 'PZ', magazzino: '01', lottoPf: 'LA', causale: '103', codOpMag: '850', operatore: 'N.CELESTINO'),
            new CaricoProduzione(data: '02/11/2026 08.00.00', articolo: 'B', descrizione: '', lotto: 'LB', quantita: 2, um: 'PZ', magazzino: '01', lottoPf: 'LB', causale: '103', codOpMag: '50', operatore: 'n.celestino'),
            new CaricoProduzione(data: '03/11/2026 08.00.00', articolo: 'C', descrizione: '', lotto: 'LC', quantita: 3, um: 'PZ', magazzino: '01', lottoPf: 'LC', causale: '103', codOpMag: '999', operatore: 'N.CELESTINO'),
            new CaricoProduzione(data: '04/11/2026 08.00.00', articolo: 'D', descrizione: '', lotto: 'LD', quantita: 4, um: 'PZ', magazzino: '01', lottoPf: 'LD', causale: '103', codOpMag: '850', operatore: 'M.ROSSI'),
        ];
    }

    public function test_filtra_per_operatore_case_insensitive_e_cod_op_mag(): void
    {
        $adapter = new FixtureCarichiProduzioneAdapter($this->dati());

        $out = $adapter->carichi('N.CELESTINO', ['50', '850']);

        // A (850) e B (50) di N.CELESTINO; C ha CodOpMag 999 (escluso), D e' di M.ROSSI (escluso).
        self::assertCount(2, $out);
        self::assertSame(['A', 'B'], array_map(static fn (CaricoProduzione $c) => $c->articolo, $out));
    }

    public function test_senza_filtri_restituisce_tutto(): void
    {
        $adapter = new FixtureCarichiProduzioneAdapter($this->dati());

        self::assertCount(4, $adapter->carichi('', []));
    }

    public function test_filtro_solo_operatore(): void
    {
        $adapter = new FixtureCarichiProduzioneAdapter($this->dati());

        $out = $adapter->carichi('M.ROSSI', []);

        self::assertCount(1, $out);
        self::assertSame('D', $out[0]->articolo);
    }
}
