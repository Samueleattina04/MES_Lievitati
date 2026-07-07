<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoFase;
use App\Models\FaseOrdine;
use App\Models\SyncQueue;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Fase 6 (§8): l'endpoint /api/sync applica le azioni della coda offline in modo idempotente
 * (un client_uuid gia' processato non viene rieseguito). Richiede DB (gira sul server).
 */
final class SyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_e_idempotente_sul_client_uuid(): void
    {
        $this->seed(MesConfigSeeder::class);
        $operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();

        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 5]);
        $step = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOCOLOMBE/PANETTONI')
            ->firstOrFail()->steps()->firstOrFail();

        $uuid = '11111111-1111-1111-1111-111111111111';
        $azione = [
            'azioni' => [[
                'client_uuid' => $uuid,
                'tipo_azione' => 'avvio_step',
                'payload' => ['step_id' => $step->id],
            ]],
        ];

        // Primo invio: applica.
        $this->actingAs($operatore)->postJson(route('operatore.sync'), $azione)
            ->assertOk()
            ->assertJsonPath('risultati.0.ok', true);
        self::assertSame(StatoFase::InCorso, $step->fresh()->stato);

        // Secondo invio identico (retry di rete): riconosciuto come duplicato, non riesegue.
        $this->actingAs($operatore)->postJson(route('operatore.sync'), $azione)
            ->assertOk()
            ->assertJsonPath('risultati.0.duplicato', true);

        self::assertSame(1, SyncQueue::where('client_uuid', $uuid)->count());
        self::assertTrue(SyncQueue::where('client_uuid', $uuid)->first()->processato);
    }

    public function test_sync_registra_errore_di_dominio_senza_riprovare(): void
    {
        $this->seed(MesConfigSeeder::class);
        $operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();

        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 5]);
        // Uno step il cui avvio e' bloccato dalle precedenze (il gusto dipende dall'impasto base).
        $stepBloccato = FaseOrdine::where('ordine_id', $ordine->id)
            ->where('articolo_prodotto_codice', 'IMPASTOTRADPIST/AN/ALB')
            ->firstOrFail()->steps()->firstOrFail();

        $this->actingAs($operatore)->postJson(route('operatore.sync'), [
            'azioni' => [[
                'client_uuid' => '22222222-2222-2222-2222-222222222222',
                'tipo_azione' => 'avvio_step',
                'payload' => ['step_id' => $stepBloccato->id],
            ]],
        ])->assertOk()->assertJsonPath('risultati.0.ok', false);

        self::assertSame(StatoFase::DaLavorare, $stepBloccato->fresh()->stato);
    }
}
