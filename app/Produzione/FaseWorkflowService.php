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

            // Non si preleva da stock una fase con consumi gia' registrati (produzione avviata).
            $haConsumi = MaterialeFase::where('fase_ordine_id', $fase->id)
                ->whereHas('consumo')
                ->exists();
            if ($haConsumi) {
                throw new WorkflowException('Fase con materiali gia confermati: non puo essere completata da stock.');
            }

            // Il lotto deve essere gia' presente a sistema (§5.3): altrimenti non e' un prelievo da stock.
            if ($this->lottoSemilavoratoSource !== null
                && ! $this->lottoSemilavoratoSource->esisteLotto($fase->articolo_prodotto_codice, $lotto)) {
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

        // Verifica giacenza sul mag. 06 (§5.1). I lotti inseriti a mano NON attivano il blocco.
        $this->controllaGiacenza($materiale, $quantitaEffettiva, $lotti);

        // Avviso soft (non bloccante): lotto manuale con quantita' oltre la giacenza TOTALE nota.
        // La conferma aggiuntiva e' gattata dal client; qui registriamo l'evento per tracciabilita'.
        $superamento = $this->rilevaSuperamentoTotale($materiale, $quantitaEffettiva, $lotti);

        return DB::transaction(function () use ($materiale, $quantitaEffettiva, $operatore, $lotti, $clientUuid, $precedente, $superamento, $confermaSuperamento) {
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

            // Tracciabilita' dell'avviso di superamento giacenza totale su lotto manuale (non bloccante).
            if ($superamento !== null) {
                LogEventi::registra('materiale_superamento_giacenza', $materiale, $operatore->id, [
                    'articolo' => $materiale->articolo_codice,
                    'quantita' => $quantitaEffettiva,
                    'giacenza_totale_nota' => $superamento['giacenza_totale'],
                    'lotti_manuali' => $superamento['lotti_manuali'],
                    'confermato_esplicitamente' => $confermaSuperamento,
                ]);
            }

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
     * Rileva il superamento della giacenza TOTALE nota (tutti i magazzini) quando sono coinvolti
     * lotti INSERITI MANUALMENTE (non presenti sul mag. 06). NON blocca: restituisce i dati per il
     * log/avviso, oppure null se la condizione non si applica.
     *
     * @param list<array{lotto:string, quantita:float|int|string}> $lotti
     * @return array{giacenza_totale:float, lotti_manuali:list<string>}|null
     */
    private function rilevaSuperamentoTotale(MaterialeFase $materiale, float $quantitaEffettiva, array $lotti): ?array
    {
        if ($this->stock === null || ! $materiale->flag_lotto || $materiale->e_semilavorato) {
            return null;
        }

        // Codici lotto presenti sul mag. 06: gli altri sono "manuali".
        $mag06 = [];
        foreach ($this->stock->lottiDisponibiliFifo($materiale->articolo_codice) as $l) {
            $mag06[$l->lotto] = true;
        }
        $manuali = [];
        foreach ($lotti as $riga) {
            $lotto = trim((string) $riga['lotto']);
            if ($lotto !== '' && ! isset($mag06[$lotto])) {
                $manuali[] = $lotto;
            }
        }
        if ($manuali === []) {
            return null; // nessun lotto manuale: nessun avviso (i lotti del 06 seguono il blocco §5.1)
        }

        $totale = $this->stock->giacenzaTotale($materiale->articolo_codice);
        if ($quantitaEffettiva <= $totale + 1e-9) {
            return null; // entro la giacenza totale nota: nessun avviso
        }

        return ['giacenza_totale' => $totale, 'lotti_manuali' => array_values(array_unique($manuali))];
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
