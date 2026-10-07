<?php

declare(strict_types=1);

namespace Tests\Unit\Check;

use App\Check\CheckExport;
use PHPUnit\Framework\TestCase;

/**
 * Il file di confronto (§ Check) ha due fogli con intestazione [Codice articolo, Descrizione] e una riga
 * per articolo. Verifica struttura e contenuti; logica pura, nessun DB.
 */
final class CheckExportTest extends TestCase
{
    public function test_due_fogli_con_intestazione_e_righe(): void
    {
        $risultato = [
            'soloEsolver' => [
                ['codice' => 'PAN0104', 'descrizione' => 'Panettone'],
                ['codice' => 'COL0205', 'descrizione' => 'Colomba'],
            ],
            'soloOmni' => [
                ['codice' => 'TOR0300', 'descrizione' => 'Torrone'],
            ],
        ];

        $fogli = CheckExport::fogli($risultato);

        self::assertCount(2, $fogli);
        self::assertSame('Esolver non in Omni', $fogli[0]['name']);
        self::assertSame('Omni non in Esolver', $fogli[1]['name']);

        // Foglio 1: intestazione + 2 articoli.
        self::assertSame(['Codice articolo', 'Descrizione'], $fogli[0]['rows'][0]);
        self::assertSame(['PAN0104', 'Panettone'], $fogli[0]['rows'][1]);
        self::assertCount(3, $fogli[0]['rows']);

        // Foglio 2: intestazione + 1 articolo.
        self::assertSame(['TOR0300', 'Torrone'], $fogli[1]['rows'][1]);
        self::assertCount(2, $fogli[1]['rows']);
    }

    public function test_fogli_vuoti_hanno_solo_intestazione(): void
    {
        $fogli = CheckExport::fogli(['soloEsolver' => [], 'soloOmni' => []]);

        self::assertCount(1, $fogli[0]['rows']);
        self::assertCount(1, $fogli[1]['rows']);
        self::assertSame(['Codice articolo', 'Descrizione'], $fogli[0]['rows'][0]);
    }
}
