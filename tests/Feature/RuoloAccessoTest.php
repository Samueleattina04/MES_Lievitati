<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RuoloUtente;
use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\OrdineProduzione;
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

    public function test_gestione_ordini_pianificazione_admin_e_backoffice(): void
    {
        // Change #3: anche il Backoffice puo' creare/gestire ordini (oltre a Pianificazione e Admin).
        foreach ([RuoloUtente::Pianificazione, RuoloUtente::Admin, RuoloUtente::Backoffice] as $r) {
            $this->actingAs($this->u($r))->get(route('ordini.index'))->assertOk();
            $this->actingAs($this->u($r))->get(route('ordini.create'))->assertOk();
        }

        // L'operatore resta escluso dalla gestione ordini.
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

    /**
     * Change #1: l'avanzamento (coda operatore + area produzione) e' consentito a Operatore e
     * Backoffice; NON a Pianificazione ne' Admin.
     */
    public function test_avanzamento_produzione_backoffice_e_operatore(): void
    {
        // Coda operatore.
        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('operatore.coda'))->assertOk();
        $this->actingAs($this->u(RuoloUtente::Operatore))->get(route('operatore.coda'))->assertOk();
        $this->actingAs($this->u(RuoloUtente::Pianificazione))->get(route('operatore.coda'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Admin))->get(route('operatore.coda'))->assertForbidden();

        // Area produzione (chiusura massiva): backoffice si', pianificazione/admin no.
        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('produzione.index'))->assertOk();
        $this->actingAs($this->u(RuoloUtente::Pianificazione))->get(route('produzione.index'))->assertForbidden();
        $this->actingAs($this->u(RuoloUtente::Admin))->get(route('produzione.index'))->assertForbidden();
    }

    /**
     * Change #1: l'operatore vede/opera solo sui propri reparti; il backoffice su tutti.
     */
    public function test_operatore_vincolato_ai_reparti_backoffice_no(): void
    {
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 5]);

        // Step di confezionamento (reparto CONF) e step di impasto (reparto IMP).
        $stepConf = $this->stepDi($ordine, 'PAN0104');
        $stepImp = $this->stepDi($ordine, 'IMPASTOCOLOMBE/PANETTONI');

        // Mario Rossi (primo operatore seed) e' abilitato solo a IMP.
        $mario = User::where('name', 'Mario Rossi (Impasto)')->firstOrFail();
        $this->actingAs($mario)->get(route('operatore.fase', $stepConf->id))->assertForbidden();
        $this->actingAs($mario)->get(route('operatore.fase', $stepImp->id))->assertOk();

        // Il backoffice non e' vincolato: accede a qualunque reparto.
        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('operatore.fase', $stepConf->id))->assertOk();
        $this->actingAs($this->u(RuoloUtente::Backoffice))->get(route('operatore.fase', $stepImp->id))->assertOk();
    }

    private function stepDi(OrdineProduzione $ordine, string $articolo): FaseOrdineStep
    {
        $fase = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', $articolo)->firstOrFail();

        return $fase->steps()->orderBy('ordine')->firstOrFail();
    }
}
