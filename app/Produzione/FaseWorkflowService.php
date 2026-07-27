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
use App\Stock\Contracts\LottoSemilavoratoSourceInterface;
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
        private readonly ?LottoSemilavoratoSourceInterface $lottoSemilavoratoSource = null,
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
            // Un nodo condiviso completato da stock (§5.3) e' gia' marcato split_completato: non
            // richiede una ripartizione (non c'e' una quantita' prodotta da dividere).
            if ($figlia->is_nodo_condiviso && ! $figlia->split_completato) {
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
     * Completa una fase-nodo "da stock" (§5.3, change #3): si indica un lotto di semilavorato
     * GIA' ESISTENTE a sistema; la fase e' chiusa automaticamente SENZA consumare i propri
     * componenti (prelievo da stock). Il lotto indicato diventa il lotto prodotto della fase,
     * cosi' la genealogia risale correttamente e la propagazione verso i padri funziona come per
     * la produzione in quest'ordine.
     */
    public function completaDaStock(
        FaseOrdine $fase,
        string $lotto,
        User $operatore,
        ?string $clientUuid = null,
    ): FaseOrdine {
        $lotto = trim($lotto);
        if ($lotto === '') {
            throw new WorkflowException('Indicare il lotto di semilavorato esistente per il prelievo da stock.');
        }

        return DB::transaction(function () use ($fase, $lotto, $operatore, $clientUuid) {
            $fase = FaseOrdine::whereKey($fase->id)->lockForUpdate()->firstOrFail();

            if ($fase->stato === StatoFase::Chiusa) {
                return $fase; // idempotente
            }

            // Prelievo da stock: la fase NON produce, quindi eventuali consumi gia' registrati sui
            // suoi componenti (es. propagati in automatico dalla chiusura dei figli, o inseriti prima
            // di cambiare idea) vengono SCARTATI: non hanno senso se il semilavorato arriva da stock.
            // Le righe lotto associate cadono in cascade (FK consumo_materiale_lotti).
            $materialiIds = MaterialeFase::where('fase_ordine_id', $fase->id)->pluck('id');
            ConsumoMateriale::whereIn('materiale_fase_id', $materialiIds)->delete();

            // Il lotto deve essere gia' presente a sistema (§5.3): storico lotti_prodotto OPPURE
            // giacenza reale sul gestionale (qualunque magazzino, change #2). Altrimenti non e' stock.
            if (! $this->lottoEsistente($fase->articolo_prodotto_codice, $lotto)) {
                throw new WorkflowException(sprintf(
                    'Il lotto %s non risulta esistente a sistema per %s: impossibile prelevare da stock.',
                    $lotto, $fase->articolo_prodotto_codice,
                ));
            }

            $qta = (float) $fase->quantita_pianificata;

            // Chiude tutti gli step SENZA consumo dei componenti.
            FaseOrdineStep::where('fase_ordine_id', $fase->id)->update([
                'stato' => StatoFase::Chiusa->value,
                'timestamp_inizio' => now(),
                'timestamp_fine' => now(),
                'operatore_id' => $operatore->id,
            ]);

            $fase->update([
                'stato' => StatoFase::Chiusa,
                'timestamp_inizio' => $fase->timestamp_inizio ?? now(),
                'timestamp_fine' => now(),
                'quantita_prodotta' => $qta,
                'operatore_id' => $operatore->id,
                'reparto_step_corrente_id' => null,
                'completata_da_stock' => true,
                // Nodo condiviso da stock: nessuna ripartizione necessaria (marcato completato).
                'split_completato' => $fase->is_nodo_condiviso ? true : $fase->split_completato,
            ]);

            // Lotto prodotto = lotto esistente indicato (per genealogia e propagazione ai padri, §5.3).
            LottoProdotto::updateOrCreate(
                ['fase_ordine_id' => $fase->id],
                [
                    'articolo_codice' => $fase->articolo_prodotto_codice,
                    'lotto' => $lotto,
                    'quantita' => $qta,
                    'creato_da_id' => $operatore->id,
                    'client_uuid' => $clientUuid,
                ],
            );

            // Riporta il lotto sulle righe-componente delle fasi successive (§5.3, change #1).
            $this->propagaLottoAiPadri($fase, $lotto, $operatore);

            LogEventi::registra('fase_completata_da_stock', $fase, $operatore->id, [
                'articolo' => $fase->articolo_prodotto_codice,
                'lotto' => $lotto,
                'quantita' => $qta,
            ]);

            $this->verificaCompletamentoOrdine($fase);

            return $fase;
        });
    }

    /**
     * Un lotto e' "esistente a sistema" se e' gia' registrato in lotti_prodotto (storico MES) oppure
     * se risulta a giacenza sul gestionale su un qualunque magazzino (change #2).
     */
    private function lottoEsistente(string $articolo, string $lotto): bool
    {
        $lotto = trim($lotto);

        if ($this->lottoSemilavoratoSource !== null
            && $this->lottoSemilavoratoSource->esisteLotto($articolo, $lotto)) {
            return true;
        }

        if ($this->stock !== null) {
            foreach ($this->stock->lottiTuttiMagazzini($articolo) as $l) {
                if (trim((string) ($l['lotto'] ?? '')) === $lotto) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Propaga il lotto prodotto/prelevato di una fase sulle righe-componente semilavorato delle fasi
     * padre che lo consumano, pre-registrando il consumo (§5.3, change #1). Cosi', nelle fasi
     * successive, il lotto compare gia' inserito senza doverlo digitare di nuovo. Non sovrascrive un
     * consumo gia' registrato (eventuale correzione manuale).
     */
    private function propagaLottoAiPadri(FaseOrdine $fase, ?string $lotto, User $operatore): void
    {
        $lotto = $lotto !== null ? trim($lotto) : '';
        if ($lotto === '') {
            return;
        }

        $materialiPadre = MaterialeFase::where('fase_produttrice_id', $fase->id)
            ->where('e_semilavorato', true)
            ->where('articolo_codice', $fase->articolo_prodotto_codice)
            ->get();

        foreach ($materialiPadre as $materiale) {
            // Rispetta un consumo gia' inserito (magari corretto a mano).
            if ($materiale->consumo()->exists()) {
                continue;
            }

            $qta = (float) $materiale->quantita_pianificata;
            $consumo = ConsumoMateriale::updateOrCreate(
                ['materiale_fase_id' => $materiale->id],
                [
                    'quantita_effettiva' => $qta,
                    'confermato_da_id' => $operatore->id,
                    'confermato_at' => now(),
                ],
            );
            $consumo->lotti()->delete();
            $consumo->lotti()->create(['lotto' => $lotto, 'quantita' => $qta]);

            LogEventi::registra('materiale_confermato', $materiale, $operatore->id, [
                'articolo' => $materiale->articolo_codice,
                'pianificata' => $qta,
                'nuovo' => ['quantita' => $qta, 'lotti' => [['lotto' => $lotto, 'quantita' => $qta]]],
                'auto_propagato' => true,
            ]);
        }
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
        bool $confermaSuperamento = false,
    ): ConsumoMateriale {
        if ($quantitaEffettiva < 0) {
            throw new WorkflowException('La quantita consumata non puo essere negativa.');
        }

        // Immutabilita' dopo la chiusura (audit): i consumi di una fase chiusa non si modificano.
        // Finche' la fase e' aperta, invece, la riga gia' confermata puo' essere corretta.
        $fase = $materiale->fase()->first();
        if ($fase !== null && $fase->stato === StatoFase::Chiusa) {
            throw new WorkflowException('Fase chiusa: i materiali non sono piu modificabili.');
        }

        // Valori precedenti (per il log prima/dopo in caso di correzione).
        $precedente = $materiale->consumo()->with('lotti')->first();

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

        // Verifica giacenza sul mag. 06 (§5.1): blocca su QUALSIASI articolo se la quantita' confermata
        // supera la giacenza disponibile, anche con lotti inseriti a mano. Nessun override possibile.
        $this->controllaGiacenza($materiale, $quantitaEffettiva, $lotti);

        return DB::transaction(function () use ($materiale, $quantitaEffettiva, $operatore, $lotti, $clientUuid, $precedente) {
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

            $eCorrezione = $precedente !== null;
            $nuovoStato = [
                'quantita' => $quantitaEffettiva,
                'lotti' => array_map(fn ($l) => ['lotto' => trim((string) $l['lotto']), 'quantita' => (float) $l['quantita']], $lotti),
            ];
            $statoPrecedente = $eCorrezione ? [
                'quantita' => (float) $precedente->quantita_effettiva,
                'lotti' => $precedente->lotti->map(fn ($l) => ['lotto' => $l->lotto, 'quantita' => (float) $l->quantita])->all(),
            ] : null;

            // Log distinto per la correzione (valore precedente/nuovo), coerente con l'audit trail (§11).
            LogEventi::registra($eCorrezione ? 'materiale_modificato' : 'materiale_confermato', $materiale, $operatore->id, [
                'articolo' => $materiale->articolo_codice,
                'pianificata' => (float) $materiale->quantita_pianificata,
                'precedente' => $statoPrecedente,
                'nuovo' => $nuovoStato,
            ]);

            return $consumo;
        });
    }

    /**
     * Blocco per giacenza insufficiente sul mag. 06 (§5.1). Vale per QUALSIASI articolo:
     *  1) livello articolo: la quantita' confermata non puo' superare la giacenza dell'articolo sul
     *     mag. 06. Cosi' anche i lotti digitati a mano non permettono di consumare piu' del disponibile.
     *  2) rifinitura per articolo a lotto: nessuna riga puo' superare la giacenza del proprio lotto sul
     *     mag. 06 (per i lotti effettivamente presenti sul 06).
     * I semilavorati prodotti internamente sono esclusi: non stanno a magazzino, la loro disponibilita'
     * e' governata dalla fase produttrice (precedenze/split).
     *
     * @param list<array{lotto:string, quantita:float|int|string}> $lotti
     */
    private function controllaGiacenza(MaterialeFase $materiale, float $quantitaEffettiva, array $lotti): void
    {
        if (! $this->verificaGiacenza || $this->stock === null) {
            return;
        }

        if ($materiale->e_semilavorato) {
            return;
        }

        $codice = $materiale->articolo_codice;

        // 1) Blocco a livello articolo (qualsiasi articolo, a lotto o no).
        $disponibileArticolo = $this->stock->giacenzaArticolo($codice);
        if ($quantitaEffettiva > $disponibileArticolo + 1e-9) {
            throw new WorkflowException(sprintf(
                'Giacenza mag. 06 insufficiente per %s: richiesti %s, disponibili %s.',
                $codice, $this->fmt($quantitaEffettiva), $this->fmt($disponibileArticolo),
            ));
        }

        if (! $materiale->flag_lotto) {
            return;
        }

        // 2) Rifinitura per lotto: la quantita' assegnata a ciascun lotto presente sul mag. 06 non puo'
        //    superarne la giacenza. Aggrega le richieste per codice lotto (l'operatore puo' ripeterlo).
        $dispPerLotto = [];
        foreach ($this->stock->lottiDisponibiliFifo($codice) as $l) {
            $dispPerLotto[$l->lotto] = ($dispPerLotto[$l->lotto] ?? 0.0) + $l->quantita;
        }

        $richiestaPerLotto = [];
        foreach ($lotti as $riga) {
            $lotto = trim((string) $riga['lotto']);
            $richiestaPerLotto[$lotto] = ($richiestaPerLotto[$lotto] ?? 0.0) + (float) $riga['quantita'];
        }

        foreach ($richiestaPerLotto as $lotto => $qta) {
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

                // Riporta il lotto in uscita sulle righe-componente delle fasi successive (§5.3, change #1).
                $this->propagaLottoAiPadri($fase, $lottoProdotto, $operatore);

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

    /**
     * Chiude una fase-nodo SENZA passare dagli step: usata dalla chiusura massiva backoffice per gli
     * articoli non configurati a reparto/tipo-fase (nessuno step), §8. Applica le stesse validazioni
     * della chiusura normale (materiali confermati, lotto obbligatorio dove previsto, lotto in uscita)
     * e propaga il lotto ai padri. Il flusso guidato operatore, invece, richiede sempre gli step.
     */
    public function chiudiFaseDiretta(FaseOrdine $fase, ?string $lottoProdotto, ?float $quantitaProdotta, User $operatore): FaseOrdine
    {
        return DB::transaction(function () use ($fase, $lottoProdotto, $quantitaProdotta, $operatore) {
            $fase = FaseOrdine::whereKey($fase->id)->lockForUpdate()->firstOrFail();
            if ($fase->stato === StatoFase::Chiusa) {
                return $fase; // idempotente
            }

            $mancanti = MaterialeFase::where('fase_ordine_id', $fase->id)->whereDoesntHave('consumo')->count();
            if ($mancanti > 0) {
                throw new WorkflowException("Confermare tutti i materiali prima di chiudere ({$mancanti} mancanti).");
            }
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

            $richiedeLotto = Articolo::where('codice', $fase->articolo_prodotto_codice)->first()?->richiedeLotto() ?? false;
            $lottoProdotto = $lottoProdotto !== null ? trim($lottoProdotto) : null;
            if ($richiedeLotto && ($lottoProdotto === null || $lottoProdotto === '')) {
                throw new WorkflowException('Inserire il lotto del prodotto in uscita per chiudere la fase.');
            }

            $qtaProdotta = $quantitaProdotta ?? (float) $fase->quantita_pianificata;
            $fase->update([
                'stato' => StatoFase::Chiusa,
                'timestamp_inizio' => $fase->timestamp_inizio ?? now(),
                'timestamp_fine' => now(),
                'quantita_prodotta' => $qtaProdotta,
                'operatore_id' => $operatore->id,
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

            $this->propagaLottoAiPadri($fase, $lottoProdotto, $operatore);

            LogEventi::registra('fase_chiusa', $fase, $operatore->id, [
                'quantita_prodotta' => $qtaProdotta,
                'lotto_prodotto' => $lottoProdotto,
                'senza_step' => true,
            ]);
            $this->verificaCompletamentoOrdine($fase);

            return $fase;
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
