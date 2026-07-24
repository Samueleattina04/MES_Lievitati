<?php

declare(strict_types=1);

namespace App\Ordini;

use App\Bom\BomExplosion;
use App\Bom\BomRow;
use App\Enums\TipoArticolo;
use App\Models\Articolo;
use App\Models\ArticoloConfigurazioneMes;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Ordini\Planning\PhasePlan;
use App\Ordini\Planning\PlannedPhase;
use Illuminate\Support\Facades\DB;

/**
 * Materializza in MySQL il piano di fasi di un ordine (§4.1): aggiorna la cache articoli, congela
 * la distinta esplosa, crea fasi/materiali/step/precedenze. Da qui in poi l'esecuzione lavora solo
 * sui dati locali. Tutto in un'unica transazione.
 */
final class OrderMaterializer
{
    public function __construct(
        private readonly int $decimali = 6,
    ) {}

    /**
     * @param  array<string,bool>  $flagLotto  Mappa codice articolo => "gestito a lotti" dall'anagrafica
     *                                          del gestionale (§5.2). Popola flag_lotto in automatico.
     */
    public function materializza(OrdineProduzione $ordine, BomExplosion $esplosione, PhasePlan $piano, array $flagLotto = []): void
    {
        DB::transaction(function () use ($ordine, $esplosione, $piano, $flagLotto) {
            $this->aggiornaCacheArticoli($esplosione, $flagLotto);
            $this->congelaDistinta($ordine, $esplosione);

            $config = $this->caricaConfigurazioni($piano);
            $fasiPerCodice = $this->creaFasi($ordine, $piano, $config);
            $this->creaMaterialiEStep($piano, $fasiPerCodice, $config);
            $this->creaPrecedenze($piano, $fasiPerCodice);

            $ordine->forceFill(['esploso_at' => now()])->save();
        });
    }

    /**
     * @param  array<string,bool>  $flagLotto
     */
    private function aggiornaCacheArticoli(BomExplosion $esplosione, array $flagLotto = []): void
    {
        // Un articolo puo' comparire come nodo (prodotto) e come materiale: raccogliamo il tipo.
        $prodotti = collect($esplosione->codiciNodiProdotti())->flip();

        foreach ($esplosione->righe()->unique('articolo') as $riga) {
            /** @var BomRow $riga */
            $tipo = $prodotti->has($riga->articolo) || $riga->isProdotto
                ? TipoArticolo::Prodotto
                : TipoArticolo::Acquistato;

            $articolo = Articolo::firstOrNew(['codice' => $riga->articolo]);
            $articolo->descrizione = $riga->descrizione ?? $articolo->descrizione;
            $articolo->udm = $riga->udm ?? $articolo->udm;
            $articolo->udm_tecnica = $riga->udm ?? $articolo->udm_tecnica;
            $articolo->tipo = $tipo;
            // flag_lotto dall'anagrafica del gestionale se disponibile (§5.2); altrimenti false alla
            // creazione, invariato se gia' esistente. L'override manuale (flag_lotto_override) vince
            // comunque in Articolo::richiedeLotto().
            if (array_key_exists($riga->articolo, $flagLotto)) {
                $articolo->flag_lotto = $flagLotto[$riga->articolo];
            } elseif (! $articolo->exists) {
                $articolo->flag_lotto = false;
            }
            $articolo->save();
        }
    }

    private function congelaDistinta(OrdineProduzione $ordine, BomExplosion $esplosione): void
    {
        $qta = (float) $ordine->quantita;
        $righe = $esplosione->righe()->map(fn (BomRow $r) => [
            'ordine_id' => $ordine->id,
            'articolo_padre_codice' => $r->articoloPadre,
            'articolo_figlio_codice' => $r->articolo,
            'descrizione' => $r->descrizione,
            'quantita' => round($r->qtaPerUnita * $qta, $this->decimali),
            'qta_per_unita' => round($r->qtaPerUnita, $this->decimali),
            'udm' => $r->udm,
            'livello_relativo' => $r->livello,
            'posizione' => $r->posizione,
            'e_nodo_prodotto' => $r->isProdotto,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($righe, 500) as $chunk) {
            DB::table('distinta_righe')->insert($chunk);
        }
    }

    /**
     * @param array<string,ArticoloConfigurazioneMes> $config
     * @return array<string,FaseOrdine>
     */
    private function creaFasi(OrdineProduzione $ordine, PhasePlan $piano, array $config): array
    {
        $fasi = [];
        foreach ($piano->fasi as $fase) {
            $cfg = $config[$fase->articoloCodice] ?? null;
            $tipoFaseId = $cfg?->tipo_fase_id;
            $primoReparto = $this->primoRepartoId($cfg);

            $fasi[$fase->articoloCodice] = FaseOrdine::create([
                'ordine_id' => $ordine->id,
                'articolo_prodotto_codice' => $fase->articoloCodice,
                'descrizione' => $fase->descrizione,
                'quantita_pianificata' => $fase->quantitaPianificata,
                'udm' => $fase->udm,
                'livello_relativo' => $fase->livello,
                'is_nodo_condiviso' => $fase->isCondiviso,
                'tipo_fase_id' => $tipoFaseId,
                'reparto_step_corrente_id' => $primoReparto,
            ]);
        }

        return $fasi;
    }

    /**
     * @param array<string,FaseOrdine> $fasiPerCodice
     * @param array<string,ArticoloConfigurazioneMes> $config
     */
    private function creaMaterialiEStep(PhasePlan $piano, array $fasiPerCodice, array $config): void
    {
        foreach ($piano->fasi as $plan) {
            $fase = $fasiPerCodice[$plan->articoloCodice];

            $materiali = [];
            $pos = 0;
            foreach ($plan->materiali as $mat) {
                $articolo = Articolo::where('codice', $mat->articoloCodice)->first();
                $materiali[] = [
                    'fase_ordine_id' => $fase->id,
                    'articolo_codice' => $mat->articoloCodice,
                    'descrizione' => $mat->descrizione,
                    'quantita_pianificata' => $mat->quantitaPianificata,
                    'udm' => $mat->udm,
                    // Il lotto e' richiesto dove l'anagrafica lo prevede (§5.2). I semilavorati NON
                    // sono piu' esclusi (change #2): sulla riga-componente semilavorato il lotto della
                    // fase produttrice viene propagato (pre-compilato ma modificabile, §5.3) e va
                    // comunque valorizzato per chiudere la fase padre.
                    'flag_lotto' => $articolo?->richiedeLotto() ?? false,
                    'e_semilavorato' => $mat->eSemilavorato,
                    // Semilavorato prodotto in questo ordine: la fase produttrice ha lo stesso codice.
                    'fase_produttrice_id' => $mat->eSemilavorato
                        ? ($fasiPerCodice[$mat->articoloCodice]->id ?? null)
                        : null,
                    'posizione' => $pos++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($materiali !== []) {
                DB::table('materiali_fase')->insert($materiali);
            }

            $this->creaStep($fase, $config[$plan->articoloCodice] ?? null);
        }
    }

    private function creaStep(FaseOrdine $fase, ?ArticoloConfigurazioneMes $cfg): void
    {
        // Caso 1: TipoFase configurato -> istanzia i suoi step (fase multi-reparto, §3).
        if ($cfg?->tipoFase && $cfg->tipoFase->steps->isNotEmpty()) {
            foreach ($cfg->tipoFase->steps as $i => $step) {
                $fase->steps()->create([
                    'reparto_id' => $step->reparto_id,
                    'ordine' => $step->ordine,
                    'descrizione' => $step->descrizione,
                    'consuma_materiali' => $step->consuma_materiali || $i === 0,
                ]);
            }

            return;
        }

        // Caso 2: solo reparto di default -> un unico step.
        if ($cfg?->reparto_default_id) {
            $fase->steps()->create([
                'reparto_id' => $cfg->reparto_default_id,
                'ordine' => 1,
                'consuma_materiali' => true,
            ]);
        }

        // Caso 3: nessuna configurazione -> nessuno step. La fase non e' lavorabile finche'
        // l'admin non assegna reparto/tipo fase all'articolo (configurazione MES, §5/§7).
    }

    /**
     * @param array<string,FaseOrdine> $fasiPerCodice
     */
    private function creaPrecedenze(PhasePlan $piano, array $fasiPerCodice): void
    {
        foreach ($piano->fasi as $plan) {
            $fase = $fasiPerCodice[$plan->articoloCodice];
            $figlieIds = [];
            foreach ($plan->fasiFiglieCodici as $codiceFiglia) {
                if (isset($fasiPerCodice[$codiceFiglia])) {
                    $figlieIds[] = $fasiPerCodice[$codiceFiglia]->id;
                }
            }
            if ($figlieIds !== []) {
                $fase->fasiFiglie()->attach($figlieIds);
            }
        }
    }

    /**
     * @return array<string,ArticoloConfigurazioneMes>
     */
    private function caricaConfigurazioni(PhasePlan $piano): array
    {
        return ArticoloConfigurazioneMes::with(['tipoFase.steps', 'repartoDefault'])
            ->whereIn('articolo_codice', $piano->codiciFasi())
            ->get()
            ->keyBy('articolo_codice')
            ->all();
    }

    private function primoRepartoId(?ArticoloConfigurazioneMes $cfg): ?int
    {
        if ($cfg?->tipoFase && $cfg->tipoFase->steps->isNotEmpty()) {
            return $cfg->tipoFase->steps->first()->reparto_id;
        }

        return $cfg?->reparto_default_id;
    }
}
