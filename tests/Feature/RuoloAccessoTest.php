<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuoloUtente;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test separazione ruoli (§7). Verifica sia gli accessi CONSENTITI sia — soprattutto — che ogni
 * ruolo NON possa accedere alle sezioni riservate agli altri. Richiede DB (gira sul server).
 *
 * Matrice:
 *   config /admin       -> solo Admin
 *   ordini (gestione)   -> Admin, Pianificazione   (NON Backoffice)
 *   export              -> Admin, Backoffice        (NON Pianificazione)
 *   dashboard/genealogia-> Admin, Backoffice, Pianificazione (NON Operatore)
 */
final class RuoloAccessoTest extends TestCase
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

    public function test_configurazione_admin_solo_admin(): void
    {
        $this->actingAs($this->u(RuoloUtente::Admin))->get(route('admin.utenti.index'))->assertOk();

        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('admin.utenti.index'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Pianificazione))->get(route('admin.reparti.index'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Operatore))->get(route('admin.index'))->assertForbidden();
    }

    public function test_gestione_ordini_solo_pianificazione_e_admin(): void
    {
        $this->actingAs($this->u(RuoloUtente::Pianificazione))->get(route('ordini.index'))->assertOk();
        $this->actingAs($this->u(RuoloUtente::Admin))->get(route('ordini.index'))->assertOk();

        // Backoffice NON gestisce ordini (solo dashboard + export).
        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('ordini.index'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('ordini.create'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Operatore))->get(route('ordini.index'))->assertForbidden();
    }

    public function test_export_solo_backoffice_e_admin_non_pianificazione(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 1]);

        // Pianificazione NON puo' esportare.
        $this->actingAs($this->u(RuoloUtente::Pianificazione))
            ->post(route('export.esporta', $ordine))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Operatore))
            ->post(route('export.esporta', $ordine))->assertForbidden();

        // Backoffice supera il gate (poi il controller rifiuta perche' l'ordine non e' completato: 302, non 403).
        $this->actingAs($this->u(RuoloUtente::Backoffice))
            ->post(route('export.esporta', $ordine))->assertStatus(302);
    }

    public function test_dashboard_e_genealogia_office_non_operatore(): void
    {
        foreach ([RuoloUtente::Admin, RuoloUtente::Backoffice, RuoloUtente::Pianificazione] as $r) {
            $this->actingAs($this->u($r))->get(route('dashboard'))->assertOk();
            $this->actingAs($this->u($r))->get(route('genealogia.index'))->assertOk();
        }

        $this->actingAs($this->u(RuoloUtente::Operatore))->get(route('dashboard'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Operatore))->get(route('genealogia.index'))->assertForbidden();
    }

    public function test_operatore_escluso_da_tutte_le_sezioni_backoffice(): void
    {
        $op = $this->u(RuoloUtente::Operatore);
        foreach (['dashboard', 'genealogia.index', 'ordini.index', 'admin.index'] as $rotta) {
            $this->actingAs($op)->get(route($rotta))->assertForbidden();
        }
    }
}
