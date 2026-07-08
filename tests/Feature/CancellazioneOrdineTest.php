<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuoloUtente;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Problema 3: cancellazione ordine (admin + pianificazione) consentita SOLO se "aperto" e nessuna
 * fase avviata. Richiede DB (gira sul server).
 */
final class CancellazioneOrdineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
    }

    private function u(RuoloUtente $r): User
    {
        return User::where('ruolo', $r->value)->firstOrFail();
    }

    private function ordine(): OrdineProduzione
    {
        return app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 5]);
    }

    public function test_pianificazione_cancella_ordine_aperto(): void
    {
        $ordine = $this->ordine();

        $this->actingAs($this->u(RuoloUtente::Pianificazione))
            ->delete(route('ordini.destroy', $ordine))
            ->assertRedirect(route('ordini.index'));

        $this->assertDatabaseMissing('ordini_produzione', ['id' => $ordine->id]);
        $this->assertDatabaseMissing('fasi_ordine', ['ordine_id' => $ordine->id]); // cascade
        $this->assertDatabaseHas('log_eventi', ['tipo_evento' => 'ordine_cancellato']);
    }

    public function test_admin_puo_cancellare(): void
    {
        $ordine = $this->ordine();
        $this->actingAs($this->u(RuoloUtente::Admin))
            ->delete(route('ordini.destroy', $ordine))
            ->assertRedirect(route('ordini.index'));
        $this->assertModelMissing($ordine);
    }

    public function test_non_cancellabile_se_una_fase_e_avviata(): void
    {
        $ordine = $this->ordine();
        $step = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')
            ->firstOrFail()->steps()->firstOrFail();

        // Avvia una fase: l'ordine passa "in lavorazione".
        app(FaseWorkflowService::class)->avvia($step, $this->u(RuoloUtente::Operatore));

        $this->actingAs($this->u(RuoloUtente::Pianificazione))
            ->delete(route('ordini.destroy', $ordine))
            ->assertRedirect(); // torna indietro con errore

        $this->assertDatabaseHas('ordini_produzione', ['id' => $ordine->id]); // NON cancellato
    }

    public function test_backoffice_non_puo_cancellare(): void
    {
        $ordine = $this->ordine();

        $this->actingAs($this->u(RuoloUtente::Backoffice))
            ->delete(route('ordini.destroy', $ordine))
            ->assertForbidden();

        $this->assertDatabaseHas('ordini_produzione', ['id' => $ordine->id]);
    }
}
