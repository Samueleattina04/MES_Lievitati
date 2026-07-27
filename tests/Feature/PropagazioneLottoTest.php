<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuoloUtente;
use App\Enums\StatoOrdine;
use App\Models\FaseOrdine;
use App\Models\MaterialeFase;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\GenealogiaService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Change #2 (§5.3, criterio 9): il lotto del semilavorato prodotto viene riportato (pre-compilato,
 * modificabile) sulla riga-componente della fase padre, a catena lungo tutto il grafo. Richiede DB.
 */
final class PropagazioneLottoTest extends TestCase
{
    use RefreshDatabase;

    private FaseWorkflowService $workflow;

    private User $op;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->workflow = app(FaseWorkflowService::class);
        $this->op = User::where('name', 'Sara Neri (Jolly)')->firstOrFail(); // tutti i reparti
    }

    private function creaOrdine(int $qta = 4): OrdineProduzione
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

    /**
     * Produce una fase: avvia gli step, conferma i materiali (semilavorati col lotto ereditato,
     * materie prime con lotto manuale), chiude con il lotto in uscita indicato.
     *
     * @param array<string,string> $semilavLot  articolo semilavorato => lotto da usare
     */
    private function produci(FaseOrdine $fase, array $semilavLot, string $outLot): void
    {
        foreach ($fase->steps()->orderBy('ordine')->get() as $step) {
            $this->workflow->avvia($step->fresh(), $this->op);
            if ($step->consuma_materiali) {
                foreach ($fase->materiali as $m) {
                    if (! $m->flag_lotto) {
                        $this->workflow->confermaMateriale($m, (float) $m->quantita_pianificata, $this->op, []);

                        continue;
                    }
                    $lotto = $m->e_semilavorato ? ($semilavLot[$m->articolo_codice] ?? 'SEMILAV') : 'MP-'.$m->id;
                    $this->workflow->confermaMateriale($m, (float) $m->quantita_pianificata, $this->op, [
                        ['lotto' => $lotto, 'quantita' => (float) $m->quantita_pianificata],
                    ]);
                }
            }
            $this->workflow->chiudiStep($step->fresh(), $this->op, null, $outLot);
        }
    }

    public function test_lotto_semilavorato_propagato_sulla_riga_componente_del_padre(): void
    {
        $o = $this->creaOrdine();

        // Chiudo l'impasto base con lotto BASE-L1.
        $this->produci($this->fase($o, 'IMPASTOCOLOMBE/PANETTONI'), [], 'BASE-L1');

        // Sulla fase padre, la riga-componente semilavorato deve risultare pre-compilata con BASE-L1.
        $stepPadre = $this->fase($o, 'IMPASTOTRADPIST/AN/ALB')->steps()->orderBy('ordine')->firstOrFail();

        $this->actingAs(User::where('ruolo', RuoloUtente::Backoffice->value)->firstOrFail())
            ->get(route('operatore.fase', $stepPadre->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operatore/Fase')
                ->where('materiali', function ($materiali) {
                    $sem = collect($materiali)->firstWhere('articolo', 'IMPASTOCOLOMBE/PANETTONI');
                    if ($sem === null) {
                        return false;
                    }
                    // Il lotto propagato deve essere visibile: come consumo pre-registrato (change #1)
                    // oppure come proposta. In entrambi i casi l'utente lo vede sulla riga.
                    $lottiVisibili = collect($sem['proposta_fifo'])->pluck('lotto')
                        ->merge(collect($sem['lotti'])->pluck('lotto'));

                    return (bool) $sem['semilavorato'] === true && $lottiVisibili->contains('BASE-L1');
                })
            );
    }

    public function test_chiusura_fase_riporta_il_lotto_sulle_fasi_successive(): void
    {
        $o = $this->creaOrdine();

        // Chiudo l'impasto base col lotto BASE-L1.
        $this->produci($this->fase($o, 'IMPASTOCOLOMBE/PANETTONI'), [], 'BASE-L1');

        // Change #1: il lotto deve risultare gia' RIPORTATO (pre-registrato) sulla riga-componente
        // della fase successiva che consuma quel semilavorato, senza doverlo reinserire.
        $padre = $this->fase($o, 'IMPASTOTRADPIST/AN/ALB');
        $materiale = MaterialeFase::where('fase_ordine_id', $padre->id)
            ->where('articolo_codice', 'IMPASTOCOLOMBE/PANETTONI')->firstOrFail();

        $consumo = $materiale->consumo()->with('lotti')->first();
        self::assertNotNull($consumo, 'Alla chiusura del figlio, il consumo del semilavorato del padre deve essere pre-registrato.');
        self::assertSame('BASE-L1', $consumo->lotti->first()?->lotto);
    }

    public function test_propagazione_a_catena_su_piu_livelli_mantiene_la_genealogia(): void
    {
        $o = $this->creaOrdine();

        // Catena a 4 livelli: base -> impasto gusto -> semilavorato panettone -> panettone finito.
        $this->produci($this->fase($o, 'IMPASTOCOLOMBE/PANETTONI'), [], 'L1');
        $this->produci($this->fase($o, 'IMPASTOTRADPIST/AN/ALB'), ['IMPASTOCOLOMBE/PANETTONI' => 'L1'], 'L2');
        $this->produci($this->fase($o, 'PANPIST/ANANAS/ALB750'), ['IMPASTOTRADPIST/AN/ALB' => 'L2'], 'L3');
        $this->produci($this->fase($o, 'PAN0104'), ['PANPIST/ANANAS/ALB750' => 'L3'], 'L4');

        self::assertSame(StatoOrdine::Completato, $o->fresh()->stato);

        // La genealogia a ritroso del prodotto finito risale tutta la catena fino alle materie prime.
        $albero = app(GenealogiaService::class)->aRitroso('L4');
        $lotti = $this->raccogliLotti($albero);

        self::assertContains('L3', $lotti);
        self::assertContains('L2', $lotti);
        self::assertContains('L1', $lotti);
        self::assertTrue(
            collect($lotti)->contains(fn ($l) => is_string($l) && str_starts_with($l, 'MP-')),
            'La genealogia deve raggiungere i lotti delle materie prime dell\'impasto base.',
        );
    }

    /**
     * Raccoglie ricorsivamente tutti i codici lotto presenti nell'albero di genealogia.
     *
     * @param mixed $nodo
     * @param list<string> $acc
     * @return list<string>
     */
    private function raccogliLotti($nodo, array &$acc = []): array
    {
        if (is_array($nodo) && (array_is_list($nodo))) {
            foreach ($nodo as $n) {
                $this->raccogliLotti($n, $acc);
            }

            return $acc;
        }
        if (is_array($nodo)) {
            if (isset($nodo['lotto']) && $nodo['lotto'] !== null) {
                $acc[] = $nodo['lotto'];
            }
            foreach (['consumi', 'origine'] as $k) {
                if (isset($nodo[$k])) {
                    $this->raccogliLotti($nodo[$k], $acc);
                }
            }
        }

        return $acc;
    }
}
