<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoFase;
use App\Enums\StatoOrdine;
use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\WorkflowException;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Fase 3 (§14): esecuzione operatore online. Usa PAN0104 (nessun nodo condiviso), quindi
 * l'ordine si completa senza split. Richiede DB (gira sul server con `php artisan test`).
 */
final class EsecuzioneOperatoreTest extends TestCase
{
    use RefreshDatabase;

    private FaseWorkflowService $workflow;

    private User $operatore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->workflow = app(FaseWorkflowService::class);
        $this->operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail(); // abilitata a tutti i reparti
    }

    private function creaOrdine(): OrdineProduzione
    {
        return app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => 'PAN0104',
            'quantita' => 20,
        ]);
    }

    private function step(OrdineProduzione $ordine, string $articolo, int $ordineStep = 1): FaseOrdineStep
    {
        $fase = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', $articolo)->firstOrFail();

        return $fase->steps()->where('ordine', $ordineStep)->firstOrFail();
    }

    public function test_precedenze_bottom_up_bloccano_il_padre_finche_il_figlio_non_e_chiuso(): void
    {
        $ordine = $this->creaOrdine();

        // L'impasto base non ha figli prodotti: avviabile subito.
        self::assertTrue($this->workflow->stepAvviabile($this->step($ordine, 'IMPASTOCOLOMBE/PANETTONI')));

        // Il gusto dipende dall'impasto base: NON avviabile finche' quello non e' chiuso.
        $stepTrad = $this->step($ordine, 'IMPASTOTRADPIST/AN/ALB');
        self::assertFalse($this->workflow->stepAvviabile($stepTrad));
        self::assertStringContainsString('componenti', (string) $this->workflow->motivoBlocco($stepTrad));
    }

    public function test_chiusura_bloccata_se_i_materiali_non_sono_confermati(): void
    {
        $ordine = $this->creaOrdine();
        $step = $this->step($ordine, 'IMPASTOCOLOMBE/PANETTONI');

        $this->workflow->avvia($step, $this->operatore);

        $this->expectException(WorkflowException::class);
        $this->workflow->chiudiStep($step->fresh(), $this->operatore);
    }

    public function test_flusso_completo_chiude_tutte_le_fasi_e_completa_l_ordine(): void
    {
        $ordine = $this->creaOrdine();

        // Lavora ripetutamente qualunque step avviabile, come farebbero gli operatori dalla coda.
        for ($giro = 0; $giro < 50; $giro++) {
            $stepDaLavorare = FaseOrdineStep::whereHas('fase', fn ($q) => $q->where('ordine_id', $ordine->id))
                ->where('stato', '!=', StatoFase::Chiusa->value)
                ->get()
                ->first(fn (FaseOrdineStep $s) => $this->workflow->stepAvviabile($s->fresh()));

            if ($stepDaLavorare === null) {
                break;
            }

            $this->workflow->avvia($stepDaLavorare, $this->operatore);
            if ($stepDaLavorare->consuma_materiali) {
                foreach ($stepDaLavorare->fase->materiali as $mat) {
                    // I materiali con flag_lotto richiedono almeno un lotto (§6).
                    $lotti = $mat->flag_lotto
                        ? [['lotto' => 'LOT-'.$mat->id, 'quantita' => (float) $mat->quantita_pianificata]]
                        : [];
                    $this->workflow->confermaMateriale($mat, (float) $mat->quantita_pianificata, $this->operatore, $lotti);
                }
            }
            // I nodi prodotti richiedono il lotto in uscita alla chiusura (genealogia, §6).
            $this->workflow->chiudiStep($stepDaLavorare->fresh(), $this->operatore, null, 'OUT-'.$stepDaLavorare->fase_ordine_id);
        }

        self::assertSame(0, FaseOrdine::where('ordine_id', $ordine->id)->where('stato', '!=', StatoFase::Chiusa->value)->count());
        self::assertSame(StatoOrdine::Completato, $ordine->fresh()->stato);
    }

    public function test_login_pin_operatore(): void
    {
        // PIN corretto (Mario Rossi = 1234): autenticato e reindirizzato alla coda.
        $this->post(route('operatore.pin-login'), ['pin' => '1234'])
            ->assertRedirect(route('operatore.coda'));
        $this->assertAuthenticated();

        // PIN errato: respinto.
        auth()->logout();
        $this->from(route('operatore.login'))
            ->post(route('operatore.pin-login'), ['pin' => '0000'])
            ->assertSessionHasErrors('pin');
        $this->assertGuest();
    }
}
