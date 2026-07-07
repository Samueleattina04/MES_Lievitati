<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\LottoProdotto;
use App\Models\MaterialeFase;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\GenealogiaService;
use App\Produzione\WorkflowException;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Fase 5 (§6): multi-lotto e genealogia. Completa un ordine PAN0104 inserendo i lotti,
 * poi verifica la tracciabilita' a ritroso e in avanti. Richiede DB (gira sul server).
 */
final class GenealogiaTest extends TestCase
{
    use RefreshDatabase;

    private FaseWorkflowService $workflow;

    private User $operatore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->workflow = app(FaseWorkflowService::class);
        $this->operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
    }

    public function test_multilotto_valida_la_somma(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 10]);
        $base = FaseOrdine::where('ordine_id', $ordine->id)->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')->firstOrFail();
        $step = $base->steps()->firstOrFail();
        $this->workflow->avvia($step, $this->operatore);

        $farina = $base->materiali()->where('articolo_codice', 'PT0LI25')->firstOrFail();
        $qta = (float) $farina->quantita_pianificata;

        // Somma coerente: due lotti che sommano alla quantita' (§6, esempio farina).
        $this->workflow->confermaMateriale($farina, $qta, $this->operatore, [
            ['lotto' => 'FAR-A', 'quantita' => $qta - 1],
            ['lotto' => 'FAR-B', 'quantita' => 1],
        ]);
        self::assertSame(2, $farina->consumo()->firstOrFail()->lotti()->count());

        // Somma NON coerente: deve fallire.
        $this->expectException(WorkflowException::class);
        $this->workflow->confermaMateriale($farina, $qta, $this->operatore, [
            ['lotto' => 'FAR-A', 'quantita' => $qta + 5],
        ]);
    }

    public function test_genealogia_a_ritroso_e_in_avanti(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 10]);
        $this->completaConLotti($ordine);

        $genealogia = app(GenealogiaService::class);

        // A ritroso dal prodotto finito PAN0104: deve risalire fino alla farina (materia prima).
        $lottoPan = LottoProdotto::whereHas('fase', fn ($q) => $q->where('ordine_id', $ordine->id)->where('articolo_prodotto_codice', 'PAN0104'))->firstOrFail();
        $albero = $genealogia->aRitroso($lottoPan->lotto);
        $articoliConsumati = [];
        $this->raccogliRitroso($albero, $articoliConsumati);
        self::assertContains('PT0LI25', $articoliConsumati, 'La genealogia deve risalire fino alla farina.');
        self::assertContains('IMPASTOCOLOMBE/PANETTONI', $articoliConsumati);

        // In avanti dal lotto della farina: deve arrivare al prodotto finito PAN0104.
        $farina = MaterialeFase::whereHas('fase', fn ($q) => $q->where('ordine_id', $ordine->id)->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI'))
            ->where('articolo_codice', 'PT0LI25')->firstOrFail();
        $lottoFarina = $farina->consumo()->firstOrFail()->lotti()->firstOrFail()->lotto;

        $avanti = $genealogia->inAvanti($lottoFarina);
        $articoliRaggiunti = [];
        $this->raccogliAvanti($avanti, $articoliRaggiunti);
        self::assertContains('PAN0104', $articoliRaggiunti, 'Il lotto farina deve arrivare in avanti fino a PAN0104.');
    }

    private function completaConLotti(OrdineProduzione $ordine): void
    {
        for ($giro = 0; $giro < 60; $giro++) {
            $step = FaseOrdineStep::whereHas('fase', fn ($q) => $q->where('ordine_id', $ordine->id))
                ->where('stato', '!=', 'chiusa')->get()
                ->first(fn (FaseOrdineStep $s) => $this->workflow->stepAvviabile($s->fresh()));
            if ($step === null) {
                break;
            }

            $this->workflow->avvia($step, $this->operatore);
            if ($step->consuma_materiali) {
                foreach ($step->fase->materiali as $m) {
                    $qta = (float) $m->quantita_pianificata;
                    $lotti = $m->flag_lotto ? [['lotto' => 'RAW-'.$m->id, 'quantita' => $qta]] : [];
                    $this->workflow->confermaMateriale($m, $qta, $this->operatore, $lotti);
                }
            }
            $this->workflow->chiudiStep($step->fresh(), $this->operatore, null, 'OUT-'.$step->fase_ordine_id);
        }
    }

    /** @param array<int,array<string,mixed>> $nodi */
    private function raccogliRitroso(array $nodi, array &$acc): void
    {
        foreach ($nodi as $nodo) {
            foreach ($nodo['consumi'] ?? [] as $c) {
                $acc[] = $c['articolo'];
                if (isset($c['origine'])) {
                    $this->raccogliRitroso([$c['origine']], $acc);
                }
            }
        }
    }

    /** @param array<int,array<string,mixed>> $utilizzi */
    private function raccogliAvanti(array $utilizzi, array &$acc): void
    {
        foreach ($utilizzi as $u) {
            $acc[] = $u['articolo'];
            foreach ($u['prodotti'] ?? [] as $p) {
                $this->raccogliAvanti($p['usato_in'] ?? [], $acc);
            }
        }
    }
}
