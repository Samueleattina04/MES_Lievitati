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
use ZipArchive;

/**
 * Test Fase 7 (§10): l'export genera i tracciati (ZIP) solo per ordini completati e marca
 * l'ordine "esportato" (non piu' modificabile). Richiede DB (gira sul server).
 */
final class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_solo_dopo_completamento_e_marca_esportato(): void
    {
        $this->seed(MesConfigSeeder::class);
        $operatore = User::where('name', 'Sara Neri (Jolly)')->firstOrFail();
        $workflow = app(FaseWorkflowService::class);

        $ordine = app(OrdineProduzioneService::class)->creaManuale(['articolo_finito_codice' => 'PAN0104', 'quantita' => 10]);
        $service = app(EsportazioneService::class);

        // Non ancora completato: l'export deve essere rifiutato.
        try {
            $service->esportaZip($ordine, $operatore->id);
            self::fail('Atteso RuntimeException su ordine non completato.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        // Completa l'ordine (con lotti).
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

        self::assertSame(StatoOrdine::Completato, $ordine->fresh()->stato);

        // Esporta: crea lo ZIP e marca esportato.
        $zip = $service->esportaZip($ordine->fresh(), $operatore->id);

        self::assertFileExists($zip);
        $archivio = new ZipArchive();
        $archivio->open($zip);
        self::assertSame(3, $archivio->numFiles);
        $nomi = [];
        for ($i = 0; $i < $archivio->numFiles; $i++) {
            $nomi[] = $archivio->getNameIndex($i);
        }
        $archivio->close();
        @unlink($zip);

        self::assertTrue((bool) collect($nomi)->first(fn ($n) => str_starts_with($n, 'consumi_')));
        self::assertTrue((bool) collect($nomi)->first(fn ($n) => str_starts_with($n, 'versamenti_')));
        self::assertTrue((bool) collect($nomi)->first(fn ($n) => str_starts_with($n, 'tracciato_')));

        self::assertSame(StatoOrdine::Esportato, $ordine->fresh()->stato);
        self::assertNotNull($ordine->fresh()->esportato_at);
    }
}
