<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoFase;
use App\Models\ConsumoMateriale;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\WorkflowException;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change #3 (§5.3, criterio 10): indicando per un semilavorato un lotto GIA' esistente a sistema,
 * la fase produttrice viene chiusa automaticamente SENZA consumare i componenti (prelievo da stock).
 * Richiede DB.
 */
final class CompletamentoDaStockTest extends TestCase
{
    use RefreshDatabase;

    private FaseWorkflowService $workflow;

    private User $op;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->workflow = app(FaseWorkflowService::class);
        $this->op = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
    }

    private function creaOrdine(int $qta): OrdineProduzione
    {
        return app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'PAN0104',
            'quantita' => $qta,
        ]);
    }

    private function fase(OrdineProduzione $o, string $articolo): FaseOrdine
    {
        return FaseOrdine::where('ordine_id', $o->id)->where('articolo_prodotto_codice', $articolo)->firstOrFail();
    }

    /** Produce interamente una fase a singolo step (usata per creare un lotto "storico"). */
    private function produciFase(FaseOrdine $fase, string $outLot): void
    {
        $step = $fase->steps()->orderBy('ordine')->firstOrFail();
        $this->workflow->avvia($step, $this->op);
        foreach ($fase->materiali as $m) {
            $lotti = $m->flag_lotto ? [['lotto' => 'R-'.$m->id, 'quantita' => (float) $m->quantita_pianificata]] : [];
            $this->workflow->confermaMateriale($m, (float) $m->quantita_pianificata, $this->op, $lotti);
        }
        $this->workflow->chiudiStep($step->fresh(), $this->op, null, $outLot);
    }

    public function test_lotto_esistente_chiude_la_fase_senza_consumo_e_sblocca_il_padre(): void
    {
        // Ordine A: produco davvero l'impasto base, creando il lotto storico IMP-STK.
        $a = $this->creaOrdine(5);
        $this->produciFase($this->fase($a, 'IMPASTOCOLOMBE/PANETTONI'), 'IMP-STK');

        // Ordine B: l'impasto base viene prelevato da stock indicando IMP-STK.
        $b = $this->creaOrdine(10);
        $faseB = $this->fase($b, 'IMPASTOCOLOMBE/PANETTONI');

        $this->workflow->completaDaStock($faseB, 'IMP-STK', $this->op);
        $faseB->refresh();

        self::assertSame(StatoFase::Chiusa, $faseB->stato);
        self::assertTrue($faseB->completata_da_stock);

        // Nessun componente consumato in quest'ordine.
        $consumi = ConsumoMateriale::whereIn('materiale_fase_id', $faseB->materiali->pluck('id'))->count();
        self::assertSame(0, $consumi);

        // Lotto prodotto registrato = lotto esistente.
        $this->assertDatabaseHas('lotti_prodotto', [
            'fase_ordine_id' => $faseB->id,
            'articolo_codice' => 'IMPASTOCOLOMBE/PANETTONI',
            'lotto' => 'IMP-STK',
        ]);

        // La fase padre e' ora sbloccata (precedenza soddisfatta dalla chiusura da stock).
        $stepPadre = $this->fase($b, 'IMPASTOTRADPIST/AN/ALB')->steps()->orderBy('ordine')->firstOrFail();
        self::assertTrue($this->workflow->stepAvviabile($stepPadre->fresh()));
    }

    public function test_lotto_inesistente_viene_rifiutato(): void
    {
        $b = $this->creaOrdine(10);
        $fase = $this->fase($b, 'IMPASTOCOLOMBE/PANETTONI');

        $this->expectException(WorkflowException::class);
        $this->workflow->completaDaStock($fase, 'LOTTO-CHE-NON-ESISTE', $this->op);
    }
}
