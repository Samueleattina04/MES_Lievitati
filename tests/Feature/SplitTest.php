<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\SplitService;
use App\Produzione\WorkflowException;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Fase 4 (§14, criterio 5): il nodo condiviso IMPASTOCOLOMBE genera una sola fase; dopo la
 * chiusura serve lo split per sbloccare i due gusti. Richiede DB (gira sul server).
 */
final class SplitTest extends TestCase
{
    use RefreshDatabase;

    private FaseWorkflowService $workflow;

    private SplitService $split;

    private User $operatore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->workflow = app(FaseWorkflowService::class);
        $this->split = app(SplitService::class);
        $this->operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
    }

    private function ordineAsspan(): OrdineProduzione
    {
        return app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'ASSPAN01',
            'quantita' => 10,
        ]);
    }

    private function fase(OrdineProduzione $o, string $articolo): FaseOrdine
    {
        return FaseOrdine::where('ordine_id', $o->id)->where('articolo_prodotto_codice', $articolo)->firstOrFail();
    }

    private function chiudiImpastoBase(OrdineProduzione $o): FaseOrdine
    {
        $base = $this->fase($o, 'IMPASTOCOLOMBE/PANETTONI');
        $step = $base->steps()->firstOrFail();
        $this->workflow->avvia($step, $this->operatore);
        foreach ($base->materiali as $m) {
            // I materiali con flag_lotto richiedono almeno un lotto (§6).
            $lotti = $m->flag_lotto
                ? [['lotto' => 'BASE-'.$m->id, 'quantita' => (float) $m->quantita_pianificata]]
                : [];
            $this->workflow->confermaMateriale($m, (float) $m->quantita_pianificata, $this->operatore, $lotti);
        }
        // IMPASTOCOLOMBE e' un nodo prodotto: richiede il lotto in uscita alla chiusura.
        $this->workflow->chiudiStep($step->fresh(), $this->operatore, 72.0, 'BASE-LOT-001');

        return $base->fresh();
    }

    public function test_dopo_chiusura_nodo_condiviso_i_gusti_sono_bloccati_finche_manca_lo_split(): void
    {
        $o = $this->ordineAsspan();
        $this->chiudiImpastoBase($o);

        $stepGusto1 = $this->fase($o, 'IMPASTOTRADPIST/AN/ALB')->steps()->firstOrFail();
        self::assertFalse($this->workflow->stepAvviabile($stepGusto1));
        self::assertStringContainsString('split', (string) $this->workflow->motivoBlocco($stepGusto1));
    }

    public function test_split_sblocca_entrambi_i_gusti(): void
    {
        $o = $this->ordineAsspan();
        $base = $this->chiudiImpastoBase($o);

        $g1 = $this->fase($o, 'IMPASTOTRADPIST/AN/ALB');
        $g2 = $this->fase($o, 'IMPASTOTRADPIST/PESC/CIOC');

        $this->split->registra($base, [$g1->id => 36.0, $g2->id => 36.0], $this->operatore);

        self::assertTrue($base->fresh()->split_completato);
        self::assertTrue($this->workflow->stepAvviabile($g1->steps()->firstOrFail()));
        self::assertTrue($this->workflow->stepAvviabile($g2->steps()->firstOrFail()));
    }

    public function test_split_rifiuta_somma_non_coerente(): void
    {
        $o = $this->ordineAsspan();
        $base = $this->chiudiImpastoBase($o);
        $g1 = $this->fase($o, 'IMPASTOTRADPIST/AN/ALB');
        $g2 = $this->fase($o, 'IMPASTOTRADPIST/PESC/CIOC');

        $this->expectException(WorkflowException::class);
        // 36 + 30 = 66, contro 72 prodotti: fuori tolleranza.
        $this->split->registra($base, [$g1->id => 36.0, $g2->id => 30.0], $this->operatore);
    }
}
