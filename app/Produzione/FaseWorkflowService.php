<?php

declare(strict_types=1);

namespace App\Produzione;

use App\Enums\StatoFase;
use App\Enums\StatoOrdine;
use App\Models\Articolo;
use App\Models\ConsumoMateriale;
use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\FaseSplit;
use App\Models\LottoProdotto;
use App\Models\MaterialeFase;
use App\Models\User;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Support\LogEventi;
use Illuminate\Support\Facades\DB;

/**
 * Avanzamento delle fasi in reparto (§8, §11): avvio/conferma/chiusura degli step, con
 * lock a livello di riga (lockForUpdate in transazione) per evitare doppio avvio concorrente,
 * e audit trail. Le decisioni di ammissibilita' delegano a FaseGate (regole pure).
 */
final class FaseWorkflowService
{
    public function __construct(
        private readonly float $tolleranzaMultilotto = 0.01,
        private readonly ?StockSourceAdapterInterface $stock = null,
        private readonly bool $verificaGiacenza = true,
    ) {}

    /**
     * Contesto di gating di uno step (precedenze, split, step precedenti).
     *
     * @return array{precedenzeOk:bool, splitMancante:bool, statiStepPrecedenti:list<StatoFase>}
     */
    public function contesto(FaseOrdineStep $step): array
    {
        $fase = $step->fase()->with(['fasiFiglie', 'steps'])->firstOrFail();

        $statiFiglie = $fase->fasiFiglie->map(fn (FaseOrdine $f) => $f->stato)->all();

        $splitMancante = false;
        foreach ($fase->fasiFiglie as $figlia) {
            if ($figlia->is_nodo_condiviso) {
                $haSplit = FaseSplit::where('fase_sorgente_id', $figlia->id)
                    ->where('fase_destinazione_id', $fase->id)
                    ->exists();
                if (! $haSplit) {
                    $splitMancante = true;
                    break;
                }
            }
        }

        $statiPrecedenti = $fase->steps
            ->filter(fn (FaseOrdineStep $s) => $s->ordine < $step->ordine)
            ->map(fn (FaseOrdineStep $s) => $s->stato)
            ->values()
            ->all();

        return [
            'precedenzeOk' => FaseGate::precedenzeSoddisfatte($statiFiglie),
            'splitMancante' => $splitMancante,
            'statiStepPrecedenti' => $statiPrecedenti,
        ];
    }

    public function stepAvviabile(FaseOrdineStep $step): bool
    {
        $c = $this->contesto($step);

        return FaseGate::stepAvviabile($c['precedenzeOk'], $c['splitMancante'], $c['statiStepPrecedenti'], $step->stato);
    }

    public function motivoBlocco(FaseOrdineStep $step): ?string
    {
        $c = $this->contesto($step);

        return FaseGate::motivoBlocco($c['precedenzeOk'], $c['splitMancante'], $c['statiStepPrecedenti'], $step->stato);
    }

    /**
     * Avvia uno step (§8). Registra inizio e operatore; porta fase e ordine "in lavorazione".
     */
    public function avvia(FaseOrdineStep $step, User $operatore): FaseOrdineStep
    {
        return DB::transaction(function () use ($step, $operatore) {
            $step = FaseOrdineStep::whereKey($step->id)->lockForUpdate()->firstOrFail();
            $fase = FaseOrdine::whereKey($step->fase_ordine_id)->lockForUpdate()->firstOrFail();

            if ($step->stato === StatoFase::InCorso) {
                return $step; // gia' avviato: idempotente
            }
            if (! $this->stepAvviabile($step)) {
                throw new WorkflowException($this->motivoBlocco($step) ?? 'Step non avviabile.');
            }

            $step->update([
                'stato' => StatoFase::InCorso,
                'timestamp_inizio' => $step->timestamp_inizio ?? now(),
                'operatore_id' => $operatore->id,
            ]);

            if ($fase->stato === StatoFase::DaLavorare) {
                $fase->update([
                    'stato' => StatoFase::InCorso,
                    'timestamp_inizio' => now(),
                    'operatore_id' => $operatore->id,
                    'reparto_step_corrente_id' => $step->reparto_id,
                ]);
            }

            $fase->ordine()->where('stato', StatoOrdine::Aperto->value)
                ->update(['stato' => StatoOrdine::InLavorazione->value]);

            LogEventi::registra('fase_avviata', $fase, $operatore->id, [
                'step_id' => $step->id,
                'reparto_id' => $step->reparto_id,
            ]);

            return $step;
        });
    }

    /**
     * Registra il consumo effettivo di un materiale, con eventuale ripartizione multi-lotto (§6).
     *
     * @param list<array{lotto:string, quantita:float|int|string}> $lotti
     *        Per i materiali con flag_lotto: almeno una riga; la somma deve coincidere con la
     *        quantita' confermata entro tolleranza (es. farina 28,70 = 18,70 + 10,00).
     */
    public function confermaMateriale(
        MaterialeFase $materiale,
        float $quantitaEffettiva,
        User $operatore,
        array $lotti = [],
        ?string $clientUuid = null,
    ): ConsumoMateriale {
        if ($quantitaEffettiva < 0) {
            throw new WorkflowException('La quantita consumata non puo essere negativa.');
        }

        // Normalizza: scarta righe senza codice lotto.
        $lotti = array_values(array_filter(
            $lotti,
            fn ($l) => isset($l['lotto']) && trim((string) $l['lotto']) !== '',
        ));

        if ($materiale->flag_lotto) {
            if ($lotti === []) {
                throw new WorkflowException("Inserire almeno un lotto per {$materiale->articolo_codice}.");
            }
            $sommaLotti = array_sum(array_map(fn ($l) => (float) $l['quantita'], $lotti));
            if (! Tolleranza::entro($quantitaEffettiva, $sommaLotti, $this->tolleranzaMultilotto)) {
                throw new WorkflowException(sprintf(
                    'La somma dei lotti (%s) deve coincidere con la quantita confermata (%s), tolleranza +/-%s.',
                    rtrim(rtrim(number_format($sommaLotti, 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($quantitaEffettiva, 6, '.', ''), '0'), '.'),
                    $this->tolleranzaMultilotto,
                ));
            }
        }

        // Verifica giacenza sul mag. 06 (§5.1). I lotti inseriti a mano NON attivano il blocco.
        $this->controllaGiacenza($materiale, $quantitaEffettiva, $lotti);

        return DB::transaction(function () use ($materiale, $quantitaEffettiva, $operatore, $lotti, $clientUuid) {
            $consumo = ConsumoMateriale::updateOrCreate(
                ['materiale_fase_id' => $materiale->id],
                [
                    'quantita_effettiva' => $quantitaEffettiva,
                    'confermato_da_id' => $operatore->id,
                    'confermato_at' => now(),
                    'client_uuid' => $clientUuid,
                ],
            );

            // Rigenera le righe lotto (idempotente).
            $consumo->lotti()->delete();
            foreach ($lotti as $l) {
                $consumo->lotti()->create([
                    'lotto' => trim((string) $l['lotto']),
                    'quantita' => (float) $l['quantita'],
                ]);
            }

            $modificato = abs((float) $materiale->quantita_pianificata - $quantitaEffettiva) > 1e-9;
            LogEventi::registra('materiale_confermato', $materiale, $operatore->id, [
                'articolo' => $materiale->articolo_codice,
                'pianificata' => (float) $materiale->quantita_pianificata,
                'effettiva' => $quantitaEffettiva,
                'modificata' => $modificato,
                'lotti' => array_map(fn ($l) => ['lotto' => trim((string) $l['lotto']), 'quantita' => (float) $l['quantita']], $lotti),
            ]);

            return $consumo;
        });
    }

    /**
     * Blocco per giacenza insufficiente sul mag. 06 (§5.1):
     *  - articolo NON a lotto: quantita' confermata <= giacenza articolo sul mag. 06;
     *  - articolo a lotto: per ogni riga il cui lotto E' presente sul mag. 06, la quantita' assegnata
     *    non puo' superare la giacenza di quel lotto. I lotti inseriti a mano (assenti dal mag. 06)
     *    NON attivano il blocco.
     *
     * @param list<array{lotto:string, quantita:float|int|string}> $lotti
     */
    private function controllaGiacenza(MaterialeFase $materiale, float $quantitaEffettiva, array $lotti): void
    {
        if (! $this->verificaGiacenza || $this->stock === null) {
            return;
        }

        // I semilavorati prodotti internamente non stanno sul mag. 06: la loro disponibilita' e'
        // governata dalla fase produttrice (precedenze/split), non dalla giacenza di magazzino.
        if ($materiale->e_semilavorato) {
            return;
        }

        $codice = $materiale->articolo_codice;

        if (! $materiale->flag_lotto) {
            $disponibile = $this->stock->giacenzaArticolo($codice);
            if ($quantitaEffettiva > $disponibile + 1e-9) {
                throw new WorkflowException(sprintf(
                    'Giacenza mag. 06 insufficiente per %s: richiesti %s, disponibili %s.',
                    $codice, $this->fmt($quantitaEffettiva), $this->fmt($disponibile),
                ));
            }

            return;
        }

        // Giacenza per lotto sul mag. 06 (aggregata per codice lotto).
        $dispPerLotto = [];
        foreach ($this->stock->lottiDisponibiliFifo($codice) as $l) {
            $dispPerLotto[$l->lotto] = ($dispPerLotto[$l->lotto] ?? 0.0) + $l->quantita;
        }

        foreach ($lotti as $riga) {
            $lotto = trim((string) $riga['lotto']);
            $qta = (float) $riga['quantita'];
            // Solo i lotti del mag. 06 sono soggetti al blocco; quelli manuali passano (§5.1).
            if (array_key_exists($lotto, $dispPerLotto) && $qta > $dispPerLotto[$lotto] + 1e-9) {
                throw new WorkflowException(sprintf(
                    'Giacenza mag. 06 insufficiente per il lotto %s di %s: richiesti %s, disponibili %s.',
                    $lotto, $codice, $this->fmt($qta), $this->fmt($dispPerLotto[$lotto]),
                ));
            }
        }
    }

    private function fmt(float $valore): string
    {
        return rtrim(rtrim(number_format($valore, 6, '.', ''), '0'), '.');
    }

    /**
     * Chiude uno step. Se e' lo step che consuma i materiali, richiede che tutti i materiali siano
     * stati confermati (e i componenti a lotto abbiano almeno un lotto). Se tutti gli step della
     * fase sono chiusi, chiude la fase (criterio 4) e, se tutte le fasi dell'ordine sono chiuse,
     * marca l'ordine "completato".
     */
    public function chiudiStep(
        FaseOrdineStep $step,
        User $operatore,
        ?float $quantitaProdotta = null,
        ?string $lottoProdotto = null,
    ): FaseOrdineStep {
        return DB::transaction(function () use ($step, $operatore, $quantitaProdotta, $lottoProdotto) {
            $step = FaseOrdineStep::whereKey($step->id)->lockForUpdate()->firstOrFail();
            $fase = FaseOrdine::whereKey($step->fase_ordine_id)->lockForUpdate()->firstOrFail();

            if ($step->stato === StatoFase::Chiusa) {
                return $step; // idempotente
            }
            if ($step->stato !== StatoFase::InCorso) {
                throw new WorkflowException('Lo step deve essere avviato prima di poter essere chiuso.');
            }

            if ($step->consuma_materiali) {
                // Tutti i materiali devono essere confermati.
                $mancanti = MaterialeFase::where('fase_ordine_id', $fase->id)
                    ->whereDoesntHave('consumo')
                    ->count();
                if ($mancanti > 0) {
                    throw new WorkflowException("Confermare tutti i materiali prima di chiudere ({$mancanti} mancanti).");
                }
                // Lotto obbligatorio (§5.2, §8): i componenti a lotto devono avere almeno una riga lotto.
                $senzaLotto = MaterialeFase::where('fase_ordine_id', $fase->id)
                    ->where('flag_lotto', true)
                    ->where(function ($q) {
                        $q->whereDoesntHave('consumo')
                            ->orWhereHas('consumo', fn ($c) => $c->whereDoesntHave('lotti'));
                    })
                    ->count();
                if ($senzaLotto > 0) {
                    throw new WorkflowException("Impossibile chiudere: {$senzaLotto} componente/i a lotto senza lotto valorizzato.");
                }
            }

            $step->update([
                'stato' => StatoFase::Chiusa,
                'timestamp_fine' => now(),
                'operatore_id' => $operatore->id,
            ]);

            // pluck() su relazione Eloquent applica il cast: 'stato' puo' arrivare gia' come StatoFase.
            $statiStep = $fase->steps()->pluck('stato')
                ->map(fn ($s) => $s instanceof StatoFase ? $s : StatoFase::from($s))
                ->all();

            if (FaseGate::tuttiStepChiusi($statiStep)) {
                $qtaProdotta = $quantitaProdotta ?? (float) $fase->quantita_pianificata;

                // Lotto del prodotto in uscita, inserito dall'operatore dove richiesto (§6).
                $richiedeLotto = Articolo::where('codice', $fase->articolo_prodotto_codice)->first()?->richiedeLotto() ?? false;
                $lottoProdotto = $lottoProdotto !== null ? trim($lottoProdotto) : null;
                if ($richiedeLotto && ($lottoProdotto === null || $lottoProdotto === '')) {
                    throw new WorkflowException('Inserire il lotto del prodotto in uscita per chiudere la fase.');
                }

                $fase->update([
                    'stato' => StatoFase::Chiusa,
                    'timestamp_fine' => now(),
                    'quantita_prodotta' => $qtaProdotta,
                    'reparto_step_corrente_id' => null,
                ]);

                if ($lottoProdotto !== null && $lottoProdotto !== '') {
                    LottoProdotto::updateOrCreate(
                        ['fase_ordine_id' => $fase->id],
                        [
                            'articolo_codice' => $fase->articolo_prodotto_codice,
                            'lotto' => $lottoProdotto,
                            'quantita' => $qtaProdotta,
                            'creato_da_id' => $operatore->id,
                        ],
                    );
                }

                LogEventi::registra('fase_chiusa', $fase, $operatore->id, [
                    'quantita_prodotta' => $qtaProdotta,
                    'lotto_prodotto' => $lottoProdotto,
                ]);
                $this->verificaCompletamentoOrdine($fase);
            } else {
                // Porta il corrente al prossimo step non chiuso.
                $prossimo = $fase->steps()
                    ->where('stato', '!=', StatoFase::Chiusa->value)
                    ->orderBy('ordine')
                    ->first();
                $fase->update(['reparto_step_corrente_id' => $prossimo?->reparto_id]);
                LogEventi::registra('step_chiuso', $fase, $operatore->id, ['step_id' => $step->id]);
            }

            return $step;
        });
    }

    private function verificaCompletamentoOrdine(FaseOrdine $fase): void
    {
        $apertaResidua = FaseOrdine::where('ordine_id', $fase->ordine_id)
            ->where('stato', '!=', StatoFase::Chiusa->value)
            ->exists();

        if (! $apertaResidua) {
            $fase->ordine()->update(['stato' => StatoOrdine::Completato->value]);
            LogEventi::registra('ordine_completato', $fase->ordine, null, [
                'ordine_id' => $fase->ordine_id,
            ]);
        }
    }
}
