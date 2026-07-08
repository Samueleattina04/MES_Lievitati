<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoFase;
use App\Models\FaseOrdine;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\WorkflowException;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FixtureStockAdapter;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Problema 2: correzione di un materiale gia' confermato mentre la fase e' APERTA (con log
 * prima/dopo), e immutabilita' dopo la chiusura. Richiede DB (gira sul server).
 */
final class CorrezioneMaterialeTest extends TestCase
{
    use RefreshDatabase;

    private User $op;

    private FaseOrdine $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        // Giacenza illimitata: le conferme non bloccano per giacenza in questo test.
        $this->app->instance(StockSourceAdapterInterface::class, new FixtureStockAdapter([], null));
        $this->op = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 10]);
        $this->base = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')->firstOrFail();
    }

    private function wf(): FaseWorkflowService
    {
        return app(FaseWorkflowService::class);
    }

    public function test_correzione_materiale_a_fase_aperta_con_log_prima_dopo(): void
    {
        $acqua = $this->base->materiali()->where('articolo_codice', 'ACQUA')->firstOrFail();

        $this->wf()->confermaMateriale($acqua, 3.0, $this->op);   // prima conferma
        $this->wf()->confermaMateriale($acqua, 5.0, $this->op);   // correzione

        self::assertEqualsWithDelta(5.0, (float) $acqua->consumo()->firstOrFail()->quantita_effettiva, 1e-9);

        $this->assertDatabaseHas('log_eventi', ['tipo_evento' => 'materiale_confermato']);
        $this->assertDatabaseHas('log_eventi', ['tipo_evento' => 'materiale_modificato']);

        // Il log della modifica contiene il valore precedente (3) e il nuovo (5).
        $log = \App\Models\LogEvento::where('tipo_evento', 'materiale_modificato')->latest('id')->firstOrFail();
        self::assertEqualsWithDelta(3.0, (float) $log->dati['precedente']['quantita'], 1e-9);
        self::assertEqualsWithDelta(5.0, (float) $log->dati['nuovo']['quantita'], 1e-9);
    }

    public function test_correzione_lotti_a_fase_aperta(): void
    {
        $zucchero = $this->base->materiali()->where('articolo_codice', 'ZUCCHERO-SEM')->firstOrFail();

        $this->wf()->confermaMateriale($zucchero, 5.0, $this->op, [['lotto' => 'A', 'quantita' => 5.0]]);
        $this->wf()->confermaMateriale($zucchero, 6.0, $this->op, [['lotto' => 'A', 'quantita' => 4.0], ['lotto' => 'B', 'quantita' => 2.0]]);

        $lotti = $zucchero->consumo()->firstOrFail()->lotti()->pluck('lotto')->all();
        self::assertEqualsCanonicalizing(['A', 'B'], $lotti);
    }

    public function test_immutabile_dopo_chiusura_fase(): void
    {
        $acqua = $this->base->materiali()->where('articolo_codice', 'ACQUA')->firstOrFail();
        $this->wf()->confermaMateriale($acqua, 3.0, $this->op);

        // Simula fase chiusa: da qui i materiali non sono piu' modificabili.
        $this->base->update(['stato' => StatoFase::Chiusa]);

        $this->expectException(WorkflowException::class);
        $this->wf()->confermaMateriale($acqua->fresh(), 9.0, $this->op);
    }
}
