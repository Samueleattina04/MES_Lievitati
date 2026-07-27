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
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FixtureStockAdapter;
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

    public function test_prelievo_bloccato_se_quantita_supera_la_giacenza_del_lotto(): void
    {
        $b = $this->creaOrdine(10);
        $fase = $this->fase($b, 'IMPASTOCOLOMBE/PANETTONI');
        $pianificata = (float) $fase->quantita_pianificata;

        // Il lotto STK-1 esiste a magazzino ma con meno del necessario: prelevarlo deve bloccare.
        $this->app->instance(StockSourceAdapterInterface::class, new FixtureStockAdapter([
            'IMPASTOCOLOMBE/PANETTONI' => ['lotti' => [
                ['lotto' => 'STK-1', 'quantita' => round($pianificata / 2, 3), 'rif_fifo' => 1],
            ]],
        ], null));
        $wf = app(FaseWorkflowService::class);

        $this->expectException(WorkflowException::class);
        $wf->completaDaStock($fase, 'STK-1', $this->op);
    }

    public function test_prelievo_ok_se_giacenza_del_lotto_sufficiente(): void
    {
        $b = $this->creaOrdine(10);
        $fase = $this->fase($b, 'IMPASTOCOLOMBE/PANETTONI');
        $pianificata = (float) $fase->quantita_pianificata;

        $this->app->instance(StockSourceAdapterInterface::class, new FixtureStockAdapter([
            'IMPASTOCOLOMBE/PANETTONI' => ['lotti' => [
                ['lotto' => 'STK-1', 'quantita' => $pianificata + 100.0, 'rif_fifo' => 1],
            ]],
        ], null));
        $wf = app(FaseWorkflowService::class);

        $wf->completaDaStock($fase, 'STK-1', $this->op);

        self::assertSame(StatoFase::Chiusa, $fase->fresh()->stato);
        self::assertTrue($fase->fresh()->completata_da_stock);
    }

    public function test_prelievo_multi_lotto_combina_piu_lotti_e_li_propaga_al_padre(): void
    {
        $b = $this->creaOrdine(10);
        $fase = $this->fase($b, 'IMPASTOCOLOMBE/PANETTONI');
        $meta = round(((float) $fase->quantita_pianificata) / 2, 3);

        // Due lotti a giacenza: si combinano per coprire il fabbisogno (100 + 150 nell'esempio utente).
        $this->app->instance(StockSourceAdapterInterface::class, new FixtureStockAdapter([
            'IMPASTOCOLOMBE/PANETTONI' => ['lotti' => [
                ['lotto' => 'STK-A', 'quantita' => $meta + 5.0, 'rif_fifo' => 1],
                ['lotto' => 'STK-B', 'quantita' => $meta + 5.0, 'rif_fifo' => 2],
            ]],
        ], null));
        $wf = app(FaseWorkflowService::class);

        $wf->completaDaStockMultiLotto($fase, [
            ['lotto' => 'STK-A', 'quantita' => $meta],
            ['lotto' => 'STK-B', 'quantita' => $meta],
        ], $this->op);

        $fase->refresh();
        self::assertSame(StatoFase::Chiusa, $fase->stato);
        self::assertTrue($fase->completata_da_stock);
        self::assertSame(2, \App\Models\LottoProdotto::where('fase_ordine_id', $fase->id)->count());

        // Il padre riceve il consumo del semilavorato pre-registrato con ENTRAMBI i lotti prelevati.
        $padre = $this->fase($b, 'IMPASTOTRADPIST/AN/ALB');
        $mat = \App\Models\MaterialeFase::where('fase_ordine_id', $padre->id)
            ->where('articolo_codice', 'IMPASTOCOLOMBE/PANETTONI')->firstOrFail();
        $lotti = $mat->consumo()->with('lotti')->first()?->lotti->pluck('lotto')->all() ?? [];
        self::assertContains('STK-A', $lotti);
        self::assertContains('STK-B', $lotti);
    }

    public function test_prelievo_multi_lotto_bloccato_se_una_riga_supera_la_giacenza(): void
    {
        $b = $this->creaOrdine(10);
        $fase = $this->fase($b, 'IMPASTOCOLOMBE/PANETTONI');

        $this->app->instance(StockSourceAdapterInterface::class, new FixtureStockAdapter([
            'IMPASTOCOLOMBE/PANETTONI' => ['lotti' => [
                ['lotto' => 'STK-A', 'quantita' => 100.0, 'rif_fifo' => 1],
                ['lotto' => 'STK-B', 'quantita' => 1.0, 'rif_fifo' => 2],
            ]],
        ], null));
        $wf = app(FaseWorkflowService::class);

        $this->expectException(WorkflowException::class);
        $wf->completaDaStockMultiLotto($fase, [
            ['lotto' => 'STK-A', 'quantita' => 1.0],
            ['lotto' => 'STK-B', 'quantita' => 50.0], // supera la giacenza (1.0) di STK-B
        ], $this->op);
    }
}
