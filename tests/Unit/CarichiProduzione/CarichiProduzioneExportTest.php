<?php

declare(strict_types=1);

namespace Tests\Unit\CarichiProduzione;

use App\CarichiProduzione\CaricoProduzione;
use App\CarichiProduzione\CarichiProduzioneExport;
use PHPUnit\Framework\TestCase;

/**
 * Il file dei carichi di produzione (§ Carichi) ha un foglio "Carichi" con intestazione e una riga
 * per carico, comprese le colonne famiglia/macro/microfamiglia. Logica pura, nessun DB.
 */
final class CarichiProduzioneExportTest extends TestCase
{
    public function test_foglio_carichi_con_intestazione_e_righe(): void
    {
        $carichi = [
            new CaricoProduzione(
                data: '10/11/2026 08.15.00', articolo: 'PAN0104', descrizione: 'Panettone 1kg',
                lotto: '6412-27826111', quantita: 320.0, um: 'PZ', magazzino: '01', lottoPf: '6412-27826111',
                causale: '103', codOpMag: '850', operatore: 'N.CELESTINO',
                famiglia: 'Lievitati', macrofamiglia: 'Dolci', microfamiglia: 'Panettoni',
            ),
        ];

        $fogli = CarichiProduzioneExport::fogli($carichi);

        self::assertCount(1, $fogli);
        self::assertSame('Carichi', $fogli[0]['name']);

        self::assertSame([
            'Data', 'Codice articolo', 'Descrizione', 'Lotto', 'Lotto PF', 'Quantità', 'UM',
            'Magazzino', 'Causale', 'Cod.Op.Mag', 'Operatore', 'Famiglia', 'Macrofamiglia', 'Microfamiglia',
            'Già scaricato Omni',
        ], $fogli[0]['rows'][0]);

        // La data in tabella/file e' senza orario; famiglie in coda; stato Omni "No" senza mappa.
        self::assertSame([
            '10/11/2026', 'PAN0104', 'Panettone 1kg', '6412-27826111', '6412-27826111', 320.0, 'PZ',
            '01', '103', '850', 'N.CELESTINO', 'Lievitati', 'Dolci', 'Panettoni', 'No',
        ], $fogli[0]['rows'][1]);
        self::assertCount(2, $fogli[0]['rows']);
    }

    public function test_colonna_omni_con_scarichi(): void
    {
        $carichi = [
            new CaricoProduzione(
                data: '10/11/2026 08.15.00', articolo: 'PAN0104', descrizione: '',
                lotto: '6412-27826111', quantita: 1, um: 'PZ', magazzino: '01', lottoPf: '6412-27826111',
                causale: '103', codOpMag: '850', operatore: 'N.CELESTINO',
            ),
        ];

        $fogli = CarichiProduzioneExport::fogli($carichi, ['6412-27826111' => 7]);

        // Ultima colonna = stato Omni "Sì (7)".
        self::assertSame('Sì (7)', $fogli[0]['rows'][1][14]);
    }

    public function test_data_vuota_resta_vuota(): void
    {
        $carichi = [
            new CaricoProduzione(
                data: null, articolo: 'X', descrizione: '', lotto: '', quantita: 0.0, um: '',
                magazzino: '', lottoPf: '', causale: '', codOpMag: '50', operatore: 'N.CELESTINO',
            ),
        ];

        $fogli = CarichiProduzioneExport::fogli($carichi);

        self::assertSame('', $fogli[0]['rows'][1][0]);
    }
}
