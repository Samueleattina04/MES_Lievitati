<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoOrdine;
use App\Export\EsportazioneService;
use App\Models\FaseOrdineStep;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\FaseWorkflowService;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Test export tracciati (§10): l'export ESOLVER (versamenti) genera il CSV solo per ordini completati,
 * col formato reale (intestazione fissa + righe causale;data;articolo;qta;lotto;01;850;), marca l'ordine
 * "esportato" ma resta ri-esportabile (anche verso altri gestionali). Richiede DB (gira sul server).
 */
final class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function completa(OrdineProduzione $ordine, User $operatore): void
    {
        $workflow = app(FaseWorkflowService::class);
        for ($giro = 0; $giro < 60; $giro++) {
            $step = FaseOrdineStep::whereHas('fase', fn ($q) => $q->where('ordine_id', $ordine->id))
                ->where('stato', '!=', 'chiusa')->get()
                ->first(fn (FaseOrdineStep $s) => $workflow->stepAvviabile($s->fresh()));
            if ($step === null) {
                break;
            }
            $workflow->avvia($step, $operatore);
            if ($step->consuma_materiali) {
                foreach ($step->fase->materiali as $m) {
                    $lotti = $m->flag_lotto ? [['lotto' => 'RAW-'.$m->id, 'quantita' => (float) $m->quantita_pianificata]] : [];
                    $workflow->confermaMateriale($m, (float) $m->quantita_pianificata, $operatore, $lotti);
                }
            }
            $workflow->chiudiStep($step->fresh(), $operatore, null, 'OUT-'.$step->fase_ordine_id);
        }
    }

    public function test_export_esolver_genera_il_tracciato_e_marca_esportato(): void
    {
        $this->seed(MesConfigSeeder::class);
        $operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 10]);
        $service = app(EsportazioneService::class);

        // Non ancora completato: l'export deve essere rifiutato.
        try {
            $service->esporta($ordine, 'esolver', $operatore->id);
            self::fail('Atteso RuntimeException su ordine non completato.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $this->completa($ordine, $operatore);
        self::assertSame(StatoOrdine::Completato, $ordine->fresh()->stato);

        // Esporta ESOLVER: contenuto CSV in streaming (nessun file su disco), marca "esportato".
        $file = $service->esporta($ordine->fresh(), 'esolver', $operatore->id);
        self::assertSame('contenuto', $file['tipo']);
        self::assertStringStartsWith('esolver_', $file['nome']);
        self::assertSame('text/csv', $file['mime']);

        $csv = (string) $file['contenuto'];

        // Nessun BOM; intestazione fissa; righe nel formato ESOLVER (causale 103, costanti 01;850;).
        self::assertFalse(str_starts_with($csv, "\xEF\xBB\xBF"), 'Il tracciato ESOLVER non deve avere il BOM.');
        self::assertStringStartsWith('10;20;150;180;270;260;140;', $csv);
        self::assertMatchesRegularExpression('#\r?\n103;\d{2}/\d{2}/\d{4};[^;]+;[0-9,]+;OUT-\d+;01;850;#', $csv);

        self::assertSame(StatoOrdine::Esportato, $ordine->fresh()->stato);
        self::assertNotNull($ordine->fresh()->esportato_at);
    }

    public function test_riesportabile_e_gestionale_senza_tracciato_rifiutato(): void
    {
        $this->seed(MesConfigSeeder::class);
        $operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 10]);
        $service = app(EsportazioneService::class);

        $this->completa($ordine, $operatore);
        $service->esporta($ordine->fresh(), 'esolver', $operatore->id); // -> Esportato

        // Ancora ri-esportabile anche da "Esportato" (ri-scaricabile / altri gestionali).
        $file = $service->esporta($ordine->fresh(), 'esolver', $operatore->id);
        self::assertSame('contenuto', $file['tipo']);
        self::assertNotEmpty($file['contenuto']);

        // Gestionale senza tracciato configurato (Omni): errore parlante, nessun file.
        try {
            $service->esporta($ordine->fresh(), 'omni', $operatore->id);
            self::fail('Atteso RuntimeException per gestionale senza tracciato.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
