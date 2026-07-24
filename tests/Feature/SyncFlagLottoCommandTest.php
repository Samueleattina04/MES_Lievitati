<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Bom\FixtureBomAdapter;
use App\Models\Articolo;
use App\Models\FaseOrdine;
use App\Models\MaterialeFase;
use App\Models\OrdineProduzione;
use App\Ordini\OrdineProduzioneService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comando mes:sync-flag-lotto: ri-sincronizza flag_lotto dal gestionale senza ricreare gli ordini.
 * Qui si usa un adapter finto che dichiara ACQUA "a lotti" (ACQUA non ha override nel seeder). Richiede DB.
 */
final class SyncFlagLottoCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
    }

    public function test_sync_aggiorna_articoli_e_righe_materiale_degli_ordini_aperti(): void
    {
        // Ordine creato con l'adapter fixture: ACQUA nasce senza lotto (nessun override, nessun flag).
        $ordine = app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'PAN0104',
            'quantita' => 1,
        ]);

        self::assertFalse((bool) Articolo::where('codice', 'ACQUA')->firstOrFail()->flag_lotto);
        $materiale = $this->materialeAcqua($ordine);
        self::assertFalse((bool) $materiale->flag_lotto);

        // Adapter finto: il gestionale dichiara ACQUA gestito a lotti.
        $this->app->instance(BomSourceAdapterInterface::class, new class(base_path('tests/fixtures/bom')) extends FixtureBomAdapter
        {
            public function flagLottoPerArticoli(array $codici): array
            {
                return ['ACQUA' => true];
            }
        });

        $this->artisan('mes:sync-flag-lotto', ['--ordini' => true])->assertSuccessful();

        self::assertTrue((bool) Articolo::where('codice', 'ACQUA')->firstOrFail()->flag_lotto);
        self::assertTrue((bool) $materiale->fresh()->flag_lotto, 'La riga materiale ACQUA dell\'ordine aperto deve risultare a lotto');
    }

    public function test_senza_flag_dal_gestionale_non_cambia_nulla(): void
    {
        app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 1]);

        // L'adapter fixture reale ritorna [] -> nessuna modifica, comando comunque a buon fine.
        $this->artisan('mes:sync-flag-lotto')->assertSuccessful();

        self::assertFalse((bool) Articolo::where('codice', 'ACQUA')->firstOrFail()->flag_lotto);
    }

    private function materialeAcqua(OrdineProduzione $ordine): MaterialeFase
    {
        $fase = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')->firstOrFail();

        return MaterialeFase::where('fase_ordine_id', $fase->id)
            ->where('articolo_codice', 'ACQUA')->firstOrFail();
    }
}
