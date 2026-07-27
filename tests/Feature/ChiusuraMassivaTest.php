<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StatoFase;
use App\Enums\StatoOrdine;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Models\User;
use App\Ordini\OrdineProduzioneService;
use App\Produzione\ChiusuraMassivaService;
use App\Produzione\GenealogiaService;
use App\Produzione\WorkflowException;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change #4 (§8, criterio 8): il backoffice chiude in blocco tutte le fasi di un ordine dalla
 * distinta esplosa, rispettando le precedenze bottom-up e applicando tutte le validazioni. Richiede DB.
 */
final class ChiusuraMassivaTest extends TestCase
{
    use RefreshDatabase;

    private ChiusuraMassivaService $chiusura;

    private User $backoffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
        $this->chiusura = app(ChiusuraMassivaService::class);
        $this->backoffice = User::where('email', 'backoffice@lievitati.local')->firstOrFail();
    }

    private function creaOrdine(string $articolo, int $qta): OrdineProduzione
    {
        return app(OrdineProduzioneService::class)->creaManuale([
            'articolo_finito_codice' => $articolo,
            'quantita' => $qta,
        ]);
    }

    /**
     * Costruisce il payload di chiusura massiva per tutte le fasi: materie prime con lotto manuale,
     * semilavorati lasciati vuoti (auto-propagazione dal figlio), lotto prodotto per ogni fase.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildMap(OrdineProduzione $ordine, ?callable $tweak = null): array
    {
        $ordine->load('fasi.materiali');
        $map = [];
        foreach ($ordine->fasi as $f) {
            $entry = [
                'fase_id' => $f->id,
                'modalita' => 'produzione',
                'lotto_prodotto' => 'OUT-'.$f->id,
                'quantita_prodotta' => (float) $f->quantita_pianificata,
                'materiali' => [],
            ];
            foreach ($f->materiali as $m) {
                if (! $m->flag_lotto) {
                    $entry['materiali'][] = ['materiale_id' => $m->id, 'quantita_effettiva' => (float) $m->quantita_pianificata];
                } elseif ($m->e_semilavorato) {
                    // Lasciato vuoto: la propagazione dal figlio riempie automaticamente (§5.3).
                    $entry['materiali'][] = ['materiale_id' => $m->id, 'lotti' => []];
                } else {
                    $entry['materiali'][] = [
                        'materiale_id' => $m->id,
                        'lotti' => [['lotto' => 'MP-'.$m->id, 'quantita' => (float) $m->quantita_pianificata]],
                    ];
                }
            }
            $map[$f->id] = $entry;
        }

        if ($tweak !== null) {
            $tweak($map);
        }

        return $map;
    }

    public function test_chiusura_massiva_completa_l_ordine_e_mantiene_la_genealogia(): void
    {
        $o = $this->creaOrdine('PAN0104', 10);
        $faseFinita = $this->fase($o, 'PAN0104');
        $faseBase = $this->fase($o, 'IMPASTOCOLOMBE/PANETTONI');

        $this->chiusura->chiudiOrdine($o, $this->buildMap($o), $this->backoffice);

        self::assertSame(StatoOrdine::Completato, $o->fresh()->stato);
        self::assertSame(0, FaseOrdine::where('ordine_id', $o->id)->where('stato', '!=', StatoFase::Chiusa->value)->count());

        // Genealogia coerente: il lotto del finito risale ai lotti dei semilavorati e delle materie prime.
        $albero = app(GenealogiaService::class)->aRitroso('OUT-'.$faseFinita->id);
        $lotti = $this->raccogliLotti($albero);
        self::assertContains('OUT-'.$faseBase->id, $lotti);
        self::assertTrue(collect($lotti)->contains(fn ($l) => is_string($l) && str_starts_with($l, 'MP-')));
    }

    public function test_chiusura_massiva_rispetta_le_validazioni_e_annulla_tutto_in_caso_di_errore(): void
    {
        $o = $this->creaOrdine('PAN0104', 10);
        $faseBase = $this->fase($o, 'IMPASTOCOLOMBE/PANETTONI');

        // Rimuovo i lotti dalle materie prime dell'impasto base: la validazione deve bloccare.
        $map = $this->buildMap($o, function (array &$map) use ($faseBase) {
            foreach ($map[$faseBase->id]['materiali'] as &$m) {
                $m['lotti'] = [];
            }
        });

        try {
            $this->chiusura->chiudiOrdine($o, $map, $this->backoffice);
            self::fail('La chiusura massiva doveva fallire per lotto obbligatorio mancante.');
        } catch (WorkflowException $e) {
            // atteso
        }

        // Transazione annullata: nessuna fase chiusa, ordine non completato.
        self::assertSame(0, FaseOrdine::where('ordine_id', $o->id)->where('stato', StatoFase::Chiusa->value)->count());
        self::assertNotSame(StatoOrdine::Completato, $o->fresh()->stato);
    }

    public function test_chiusura_massiva_via_http_come_backoffice(): void
    {
        $o = $this->creaOrdine('PAN0104', 6);
        $map = $this->buildMap($o);

        $this->actingAs($this->backoffice)
            ->post(route('produzione.chiudi-massivo', $o->id), ['fasi' => array_values($map)])
            ->assertRedirect(route('produzione.index'));

        self::assertSame(StatoOrdine::Completato, $o->fresh()->stato);
    }

    public function test_chiusura_massiva_chiude_anche_una_fase_senza_step_configurati(): void
    {
        // Simula un articolo NON configurato a reparto/tipo-fase: la fase non ha step. In chiusura
        // massiva deve comunque chiudersi (registrando consumi + lotto), senza lasciare l'ordine
        // "da compilare" in silenzio (§8).
        $o = $this->creaOrdine('PAN0104', 10);
        $faseSenzaStep = $this->fase($o, 'PANPIST/ANANAS/ALB750');
        $faseSenzaStep->steps()->delete();
        self::assertSame(0, $faseSenzaStep->fresh()->steps()->count());

        $this->chiusura->chiudiOrdine($o, $this->buildMap($o), $this->backoffice);

        self::assertSame(StatoOrdine::Completato, $o->fresh()->stato);
        self::assertSame(StatoFase::Chiusa, $faseSenzaStep->fresh()->stato);
        self::assertSame(0, FaseOrdine::where('ordine_id', $o->id)->where('stato', '!=', StatoFase::Chiusa->value)->count());
    }

    public function test_chiusura_massiva_gestisce_il_nodo_condiviso_con_split_automatico(): void
    {
        // ASSPAN01 contiene il nodo condiviso IMPASTOCOLOMBE/PANETTONI (consumato da due gusti).
        $o = $this->creaOrdine('ASSPAN01', 4);

        $this->chiusura->chiudiOrdine($o, $this->buildMap($o), $this->backoffice);

        self::assertSame(StatoOrdine::Completato, $o->fresh()->stato);
        self::assertTrue($this->fase($o, 'IMPASTOCOLOMBE/PANETTONI')->fresh()->split_completato);
    }

    private function fase(OrdineProduzione $o, string $articolo): FaseOrdine
    {
        return FaseOrdine::where('ordine_id', $o->id)->where('articolo_prodotto_codice', $articolo)->firstOrFail();
    }

    /**
     * @param mixed $nodo
     * @param list<string> $acc
     * @return list<string>
     */
    private function raccogliLotti($nodo, array &$acc = []): array
    {
        if (is_array($nodo) && array_is_list($nodo)) {
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
