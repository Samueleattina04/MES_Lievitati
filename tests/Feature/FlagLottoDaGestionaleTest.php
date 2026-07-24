<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Enums\OrigineOrdine;
use App\Enums\StatoOrdine;
use App\Models\Articolo;
use App\Models\OrdineProduzione;
use App\Ordini\OrderExplosionPlanner;
use App\Ordini\OrderMaterializer;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change: flag_lotto popolato in automatico dall'anagrafica del gestionale (ArtAnagrafica.MagGiacPerLotti).
 * Qui si verifica che OrderMaterializer applichi la mappa "codice => a lotti" ricevuta (§5.2). La lettura
 * reale dal gestionale (SqlServerBomAdapter::flagLottoPerArticoli) si valida sul server. Richiede DB.
 */
final class FlagLottoDaGestionaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
    }

    public function test_flag_lotto_popolato_dalla_mappa_gestionale(): void
    {
        $esplosione = app(BomSourceAdapterInterface::class)->explode('PAN0104');
        $piano = app(OrderExplosionPlanner::class)->plan($esplosione, 1);

        $ordine = OrdineProduzione::create([
            'numero' => 'TEST-FLAG-1',
            'articolo_finito_codice' => 'PAN0104',
            'quantita' => 1,
            'udm' => 'PZ',
            'data' => now()->toDateString(),
            'stato' => StatoOrdine::Aperto,
            'origine' => OrigineOrdine::Manuale,
        ]);

        // Mappa come la fornirebbe il gestionale: PT0LI25 a lotti, ACQUA no.
        app(OrderMaterializer::class)->materializza($ordine, $esplosione, $piano, [
            'PT0LI25' => true,
            'ACQUA' => false,
        ]);

        self::assertTrue((bool) Articolo::where('codice', 'PT0LI25')->firstOrFail()->flag_lotto);
        self::assertFalse((bool) Articolo::where('codice', 'ACQUA')->firstOrFail()->flag_lotto);
        // Articolo non presente nella mappa: default false alla creazione.
        self::assertFalse((bool) Articolo::where('codice', 'BURRO-P')->firstOrFail()->flag_lotto);
    }

    public function test_adapter_fixture_non_fornisce_flag(): void
    {
        // In sviluppo/test il flag arriva dalla config MES, non dal gestionale.
        self::assertSame([], app(BomSourceAdapterInterface::class)->flagLottoPerArticoli(['PT0LI25', 'ACQUA']));
    }
}
