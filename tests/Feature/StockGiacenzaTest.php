<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FaseOrdine;
use App\Models\MaterialeFase;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\WorkflowException;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FixtureStockAdapter;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Test Fase Stock/FIFO (§5, §8, criterio 12.6): blocco giacenza mag.06, bypass lotto manuale,
 * proposta FIFO, chiusura bloccata senza lotto obbligatorio. Richiede DB (gira sul server).
 */
final class StockGiacenzaTest extends TestCase
{
    use RefreshDatabase;

    private User $op;

    private OrdineProduzione $ordine;

    private FaseOrdine $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->op = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
        $this->ordine = app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'PAN0104', 'quantita' => 10,
        ]);
        $this->base = FaseOrdine::where('ordine_id', $this->ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')->firstOrFail();
    }

    /** @param array<string,mixed> $dati */
    private function stock(array $dati, ?float $default = 0.0): void
    {
        $this->app->instance(StockSourceAdapterInterface::class, new FixtureStockAdapter($dati, $default));
    }

    private function wf(): FaseWorkflowService
    {
        return app(FaseWorkflowService::class);
    }

    private function materiale(string $codice): MaterialeFase
    {
        return $this->base->materiali()->where('articolo_codice', $codice)->firstOrFail();
    }

    public function test_blocco_giacenza_insufficiente_articolo_non_a_lotto(): void
    {
        // ACQUA e' un materiale non a lotto della base.
        $this->stock(['ACQUA' => ['giacenza' => 5.0]], 0.0);

        $this->expectException(WorkflowException::class);
        $this->wf()->confermaMateriale($this->materiale('ACQUA'), 10.0, $this->op, []);
    }

    public function test_lotto_manuale_non_attiva_il_blocco(): void
    {
        // Nessun lotto sul mag.06 per ZUCCHERO-SEM: il lotto inserito a mano deve passare (§5.1).
        $this->stock([], 0.0);

        $consumo = $this->wf()->confermaMateriale(
            $this->materiale('ZUCCHERO-SEM'), 4.0, $this->op,
            [['lotto' => 'MANUALE-1', 'quantita' => 4.0]],
        );

        self::assertNotNull($consumo);
        $this->assertDatabaseHas('consumo_materiale_lotti', ['lotto' => 'MANUALE-1']);
    }

    public function test_blocco_su_lotto_del_mag06_oltre_la_disponibilita(): void
    {
        // Il lotto L1 esiste sul mag.06 con soli 3: chiederne 10 deve bloccare.
        $this->stock(['ZUCCHERO-SEM' => ['lotti' => [['lotto' => 'L1', 'quantita' => 3.0, 'rif_fifo' => 1]]]], 0.0);

        $this->expectException(WorkflowException::class);
        $this->wf()->confermaMateriale(
            $this->materiale('ZUCCHERO-SEM'), 10.0, $this->op,
            [['lotto' => 'L1', 'quantita' => 10.0]],
        );
    }

    public function test_lotto_obbligatorio_alla_conferma(): void
    {
        // Un componente a lotto senza righe lotto viene rifiutato subito alla conferma (§5.2).
        $this->stock([], 0.0);

        $this->expectException(WorkflowException::class);
        $this->wf()->confermaMateriale($this->materiale('ZUCCHERO-SEM'), 4.0, $this->op, []);
    }

    public function test_chiusura_bloccata_se_componente_a_lotto_senza_lotto(): void
    {
        // Giacenza illimitata: le conferme non bloccano. Poi rompiamo lo stato togliendo i lotti
        // a un componente a lotto e verifichiamo che la chiusura sia impedita (guardia difensiva §8).
        $this->stock([], null);
        $step = $this->base->steps()->firstOrFail();
        $this->wf()->avvia($step, $this->op);

        foreach ($this->base->materiali as $m) {
            $lotti = $m->flag_lotto ? [['lotto' => 'X-'.$m->id, 'quantita' => (float) $m->quantita_pianificata]] : [];
            $this->wf()->confermaMateriale($m, (float) $m->quantita_pianificata, $this->op, $lotti);
        }

        $zucchero = $this->materiale('ZUCCHERO-SEM');
        $zucchero->consumo->lotti()->delete();

        $this->expectException(WorkflowException::class);
        $this->wf()->chiudiStep($step->fresh(), $this->op, null, 'BASE-LOT-001');
    }

    public function test_proposta_fifo_pre_compilata_nella_schermata_operatore(): void
    {
        $zucchero = $this->materiale('ZUCCHERO-SEM');
        $pianificata = (float) $zucchero->quantita_pianificata;
        $q1 = round($pianificata / 2, 3);

        // Due lotti: il primo non basta -> la proposta deve spillare sul secondo (ordine FIFO rif_fifo).
        $this->stock(['ZUCCHERO-SEM' => ['lotti' => [
            ['lotto' => 'L1', 'quantita' => $q1, 'rif_fifo' => 1],
            ['lotto' => 'L2', 'quantita' => 9999.0, 'rif_fifo' => 2],
        ]]], null);

        $step = $this->base->steps()->firstOrFail();

        $this->actingAs($this->op)
            ->get(route('operatore.fase', $step->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Operatore/Fase')
                ->where('materiali', function ($materiali) {
                    $z = collect($materiali)->firstWhere('articolo', 'ZUCCHERO-SEM');

                    return $z !== null && count($z['proposta_fifo']) === 2
                        && $z['proposta_fifo'][0]['lotto'] === 'L1'
                        && $z['proposta_fifo'][1]['lotto'] === 'L2';
                }),
            );
    }
}
