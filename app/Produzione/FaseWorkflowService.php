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
     * Completa una fase-nodo "da stock" (§5.3, change #3) indicando UN lotto di semilavorato GIA'
     * ESISTENTE a sistema. Wrapper a lotto singolo di {@see completaDaStockMultiLotto()} (usato dal
     * flusso operatore e da /api/sync). La quantita' prelevata e' quella pianificata (o `$quantita`).
     */
    public function completaDaStock(
        FaseOrdine $fase,
        string $lotto,
        User $operatore,
        ?string $clientUuid = null,
        ?float $quantita = null,
    ): FaseOrdine {
        $lotto = trim($lotto);
        if ($lotto === '') {
            throw new WorkflowException('Indicare il lotto di semilavorato esistente per il prelievo da stock.');
        }

        $qta = $quantita ?? (float) $fase->quantita_pianificata;

        return $this->completaDaStockMultiLotto($fase, [['lotto' => $lotto, 'quantita' => $qta]], $operatore, $clientUuid);
    }

    /**
     * Completa una fase-nodo "da stock" da PIU' lotti esistenti (change: multi-lotto). La fase e'
     * chiusa SENZA consumare i propri componenti; la quantita' prodotta = somma dei lotti prelevati.
     * Ogni lotto deve esistere a sistema e — se a giacenza sul gestionale — la quantita' prelevata non
     * puo' superare la giacenza di QUEL lotto (somma su tutti i magazzini). I lotti prelevati diventano
     * i lotti prodotti della fase (una riga `lotti_prodotto` ciascuno) e vengono propagati ai padri.
     *
     * @param  list<array{lotto:string, quantita:float|int|string}>  $lottiStock
     */
    public function completaDaStockMultiLotto(
        FaseOrdine $fase,
        array $lottiStock,
        User $operatore,
        ?string $clientUuid = null,
    ): FaseOrdine {
        // Normalizza: scarta righe senza lotto, aggrega per codice lotto (l'utente puo' ripeterlo).
        $lotti = [];
        foreach ($lottiStock as $r) {
            $l = trim((string) ($r['lotto'] ?? ''));
            if ($l === '') {
                continue;
            }
            $lotti[$l] = ($lotti[$l] ?? 0.0) + (float) ($r['quantita'] ?? 0);
        }
        if ($lotti === []) {
            throw new WorkflowException('Indicare almeno un lotto di semilavorato esistente per il prelievo da stock.');
        }

        return DB::transaction(function () use ($fase, $lotti, $operatore, $clientUuid) {
            $fase = FaseOrdine::whereKey($fase->id)->lockForUpdate()->firstOrFail();

            if ($fase->stato === StatoFase::Chiusa) {
                return $fase; // idempotente
            }

            $articolo = $fase->articolo_prodotto_codice;

            // Prelievo da stock: la fase NON produce, quindi eventuali consumi gia' registrati sui suoi
            // componenti (propagati dai figli o inseriti prima) vengono SCARTATI. Le righe lotto cadono
            // in cascade (FK consumo_materiale_lotti).
            $materialiIds = MaterialeFase::where('fase_ordine_id', $fase->id)->pluck('id');
            ConsumoMateriale::whereIn('materiale_fase_id', $materialiIds)->delete();

            // Giacenza per lotto (somma su tutti i magazzini) per il cap quantita'.
            $giacenze = [];
            if ($this->stock !== null) {
                foreach ($this->stock->lottiTuttiMagazzini($articolo) as $sl) {
                    $cl = trim((string) ($sl['lotto'] ?? ''));
                    if ($cl !== '') {
                        $giacenze[$cl] = ($giacenze[$cl] ?? 0.0) + (float) ($sl['quantita'] ?? 0);
                    }
                }
            }

            foreach ($lotti as $lotto => $q) {
                // Il lotto deve esistere a sistema: storico lotti_prodotto OPPURE giacenza (qualunque mag.).
                if (! $this->lottoEsistente($articolo, (string) $lotto)) {
                    throw new WorkflowException(sprintf(
                        'Il lotto %s non risulta esistente a sistema per %s: impossibile prelevare da stock.',
                        $lotto, $articolo,
                    ));
                }
                // Cap giacenza: se il lotto e' a magazzino, non si puo' prelevare piu' del disponibile
                // (i lotti solo storici, prodotti internamente, non hanno giacenza da controllare).
                if ($this->verificaGiacenza && array_key_exists((string) $lotto, $giacenze)
                    && $q > $giacenze[(string) $lotto] + 1e-9) {
                    throw new WorkflowException(sprintf(
                        'Giacenza insufficiente per il lotto %s di %s: richiesti %s, disponibili %s (tutti i magazzini).',
                        $lotto, $articolo, $this->fmt($q), $this->fmt($giacenze[(string) $lotto]),
                    ));
                }
            }

            $qtaProdotta = array_sum($lotti);

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
                'quantita_prodotta' => $qtaProdotta,
                'operatore_id' => $operatore->id,
                'reparto_step_corrente_id' => null,
                'completata_da_stock' => true,
                // Nodo condiviso da stock: nessuna ripartizione necessaria (marcato completato).
                'split_completato' => $fase->is_nodo_condiviso ? true : $fase->split_completato,
            ]);

            // Lotti prodotti = lotti esistenti indicati (una riga ciascuno) per genealogia e propagazione.
            LottoProdotto::where('fase_ordine_id', $fase->id)->delete();
            $lottiProdotti = [];
            foreach ($lotti as $lotto => $q) {
                LottoProdotto::create([
                    'fase_ordine_id' => $fase->id,
                    'articolo_codice' => $articolo,
                    'lotto' => (string) $lotto,
                    'quantita' => $q,
                    'creato_da_id' => $operatore->id,
                    'client_uuid' => $clientUuid,
                ]);
                $lottiProdotti[] = ['lotto' => (string) $lotto, 'quantita' => $q];
            }

            // Riporta i lotti sulle righe-componente delle fasi successive (§5.3, change #1).
            $this->propagaLottiAiPadri($fase, $lottiProdotti, $operatore);

            LogEventi::registra('fase_completata_da_stock', $fase, $operatore->id, [
                'articolo' => $articolo,
                'lotti' => $lottiProdotti,
                'quantita' => $qtaProdotta,
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
     * Propaga i lotti prodotti/prelevati di una fase sulle righe-componente semilavorato delle fasi
     * padre che lo consumano, pre-registrando il consumo (§5.3, change #1). Cosi', nelle fasi
     * successive, il lotto compare gia' inserito senza doverlo digitare di nuovo. Con piu' lotti
     * (prelievo da stock multi-lotto), la quantita' pianificata del padre viene ripartita tra i lotti
     * in proporzione alle quantita' prodotte. Non sovrascrive un consumo gia' registrato (correzione).
     *
     * @param  list<array{lotto:string, quantita:float|int|string}>  $lottiProdotti
     */
    private function propagaLottiAiPadri(FaseOrdine $fase, array $lottiProdotti, User $operatore): void
    {
        // Normalizza: scarta righe senza codice lotto.
        $lotti = [];
        foreach ($lottiProdotti as $r) {
            $l = trim((string) ($r['lotto'] ?? ''));
            if ($l === '') {
                continue;
            }
            $lotti[] = ['lotto' => $l, 'quantita' => (float) ($r['quantita'] ?? 0)];
        }
        if ($lotti === []) {
            return;
        }
        $sommaQ = array_sum(array_map(fn ($l) => $l['quantita'], $lotti));

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

            // Ripartisce la quantita' del padre tra i lotti (proporzionale; uniforme se somma nulla).
            $righe = [];
            foreach ($lotti as $lp) {
                $quota = $sommaQ > 1e-9 ? $qta * ($lp['quantita'] / $sommaQ) : $qta / count($lotti);
                $righe[] = ['lotto' => $lp['lotto'], 'quantita' => $quota];
            }

            $consumo = ConsumoMateriale::updateOrCreate(
                ['materiale_fase_id' => $materiale->id],
                [
                    'quantita_effettiva' => $qta,
                    'confermato_da_id' => $operatore->id,
                    'confermato_at' => now(),
                ],
            );
            $consumo->lotti()->delete();
            foreach ($righe as $rg) {
                $consumo->lotti()->create(['lotto' => $rg['lotto'], 'quantita' => $rg['quantita']]);
            }

            LogEventi::registra('materiale_confermato', $materiale, $operatore->id, [
                'articolo' => $materiale->articolo_codice,
                'pianificata' => $qta,
                'nuovo' => ['quantita' => $qta, 'lotti' => $righe],
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
                $this->propagaLottiAiPadri($fase, [['lotto' => (string) $lottoProdotto, 'quantita' => $qtaProdotta]], $operatore);

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

            $this->propagaLottiAiPadri($fase, [['lotto' => (string) $lottoProdotto, 'quantita' => $qtaProdotta]], $operatore);

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
