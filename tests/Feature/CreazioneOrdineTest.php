<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoOrdine;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Ordini\OrdineProduzioneService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Fase 2 (§14) end-to-end sul database: creazione ordine ASSPAN01 -> materializzazione fasi.
 * Richiede un database MySQL (vedi phpunit.xml): gira sul server con `php artisan test`.
 * In test l'adapter distinte e' il FixtureBomAdapter (MES_BOM_ADAPTER=fixture).
 */
final class CreazioneOrdineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
    }

    public function test_crea_ordine_asspan01_genera_otto_fasi_e_congela_distinta(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'ASSPAN01',
            'quantita' => 10,
        ]);

        self::assertSame(StatoOrdine::Aperto, $ordine->stato);
        self::assertNotNull($ordine->esploso_at);
        self::assertSame(8, $ordine->fasi()->count(), 'ASSPAN01 deve generare 8 fasi.');

        // Criterio 2 / Fase 2: IMPASTOCOLOMBE genera UNA sola fase, marcata condivisa.
        $impasti = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')
            ->get();
        self::assertCount(1, $impasti);
        self::assertTrue($impasti->first()->is_nodo_condiviso);
        self::assertEqualsWithDelta(72.0, (float) $impasti->first()->quantita_pianificata, 1e-6);

        // La distinta e' stata congelata (snapshot).
        self::assertGreaterThan(0, $ordine->distintaRighe()->count());
    }

    public function test_precedenze_bottom_up_verso_il_nodo_condiviso(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'ASSPAN01',
            'quantita' => 5,
        ]);

        $trad = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOTRADPIST/AN/ALB')
            ->firstOrFail();

        // Criterio 5: il gusto dipende dall'impasto base.
        self::assertEqualsCanonicalizing(
            ['IMPASTOCOLOMBE/PANETTONI'],
            $trad->fasiFiglie->pluck('articolo_prodotto_codice')->all(),
        );
    }

    public function test_materiali_alimentari_ereditano_flag_lotto_dalla_configurazione(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'ASSPAN01',
            'quantita' => 1,
        ]);

        $base = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')
            ->firstOrFail();

        $farina = $base->materiali()->where('articolo_codice', 'PT0LI25')->firstOrFail();
        self::assertTrue((bool) $farina->flag_lotto, 'La farina e alimentare: deve richiedere il lotto.');
    }

    public function test_fase_semilavorata_ha_step_multi_reparto(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'ASSPAN01',
            'quantita' => 1,
        ]);

        // Criterio 4: PANPIST/* usa il tipo fase SEMILAV_PANETTONE = Lievitazione -> Forno.
        $semi = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'PANPIST/ANANAS/ALB750')
            ->firstOrFail();
        self::assertSame(2, $semi->steps()->count());
    }
}
