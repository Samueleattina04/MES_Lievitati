<?php

declare(strict_types=1);

namespace App\Produzione;

use App\Enums\StatoFase;
use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\LottoProdotto;
use App\Models\OrdineProduzione;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chiusura massiva di un ordine dal backoffice (§8, criterio 8, change #4).
 *
 * Elabora TUTTE le fasi dell'ordine in ordine bottom-up (prima i figli, poi i padri, secondo le
 * precedenze dell'albero) e le chiude in blocco, in UN'UNICA transazione: in caso di errore su
 * una fase, l'intera operazione viene annullata e l'ordine resta in uno stato coerente.
 *
 * Riusa integralmente i servizi di dominio esistenti (FaseWorkflowService, SplitService), quindi
 * TUTTE le validazioni della modalita' operatore sono applicate: giacenza mag. 06, obbligatorieta'
 * del lotto, somma multi-lotto, tolleranze, propagazione lotto semilavorati (§5.3), lock.
 */
final class ChiusuraMassivaService
{
    public function __construct(
        private readonly FaseWorkflowService $workflow,
        private readonly SplitService $splitService,
    ) {}

    /**
     * Chiude in blocco le fasi dell'ordine.
     *
     * @param array<int|string,array<string,mixed>> $fasiInput  Mappa fase_id => input:
     *   {
     *     modalita: 'produzione'|'stock',
     *     lotto_prodotto?: string,          // lotto in uscita (modalita produzione)
     *     lotto_stock?: string,             // lotto esistente (modalita stock)
     *     quantita_prodotta?: number,
     *     materiali?: [ { materiale_id, quantita_effettiva?, lotti?: [{lotto,quantita}], conferma_superamento? } ]
     *   }
     */
    public function chiudiOrdine(OrdineProduzione $ordine, array $fasiInput, User $operatore): void
    {
        /** @var Collection<int,FaseOrdine> $fasi */
        $fasi = $ordine->fasi()->with(['steps', 'materiali', 'fasiFiglie:id'])->get()->keyBy('id');

        $ordineElaborazione = $this->ordineBottomUp($fasi);

        DB::transaction(function () use ($ordine, $ordineElaborazione, $fasi, $fasiInput, $operatore) {
            foreach ($ordineElaborazione as $faseId) {
                /** @var FaseOrdine $fase */
                $fase = $fasi[$faseId];

                if ($fase->stato === StatoFase::Chiusa) {
                    continue; // gia' chiusa (idempotente / lavorata a mano)
                }

                $input = $fasiInput[$faseId] ?? [];

                try {
                    $this->elaboraFase($fase, $input, $operatore);
                } catch (WorkflowException $e) {
                    // Contestualizza l'errore alla fase e propaga: la transazione annulla tutto.
                    throw new WorkflowException($this->contestualizza($fase, $e), 0, $e);
                }
            }

            // Sicurezza: nessuna chiusura parziale silenziosa (§8). Se — per qualunque motivo — una
            // fase risulta ancora aperta dopo l'elaborazione, annulla tutto con un errore parlante
            // invece di riportare un falso "ok" lasciando l'ordine in "da compilare".
            $aperte = FaseOrdine::where('ordine_id', $ordine->id)
                ->where('stato', '!=', StatoFase::Chiusa->value)
                ->pluck('articolo_prodotto_codice')
                ->unique()
                ->values();
            if ($aperte->isNotEmpty()) {
                throw new WorkflowException(
                    'Impossibile chiudere l\'intero ordine: fasi rimaste aperte ('.$aperte->implode(', ').
                    '). Verifica i dati inseriti e la configurazione (reparto/tipo-fase) di questi articoli.'
                );
            }
        });
    }

    /**
     * Chiude una SINGOLA fase dell'ordine (bottone "Completa" per fase, change). Stessa logica della
     * chiusura in blocco ma su una fase sola, in transazione: o chiude, o lancia un WorkflowException
     * contestualizzato con il motivo (giacenza, lotto obbligatorio, precedenze, ...). Le precedenze
     * restano garantite dagli step (avvia() blocca se i figli non sono ancora chiusi).
     *
     * @param  array<string,mixed>  $input
     */
    public function chiudiFase(OrdineProduzione $ordine, int $faseId, array $input, User $operatore): FaseOrdine
    {
        /** @var FaseOrdine $fase */
        $fase = $ordine->fasi()
            ->with(['steps', 'materiali', 'fasiFiglie:id'])
            ->whereKey($faseId)
            ->firstOrFail();

        return DB::transaction(function () use ($fase, $input, $operatore) {
            if ($fase->stato === StatoFase::Chiusa) {
                return $fase; // idempotente
            }

            try {
                $this->elaboraFase($fase, $input, $operatore);
            } catch (WorkflowException $e) {
                throw new WorkflowException($this->contestualizza($fase, $e), 0, $e);
            }

            return $fase->refresh();
        });
    }

    /**
     * Elabora la chiusura di una fase secondo la modalita': 'stock' (prelievo da uno o piu' lotti
     * esistenti) oppure 'produzione' (avvio/conferma materiali/chiusura step + split se condiviso).
     *
     * @param  array<string,mixed>  $input
     */
    private function elaboraFase(FaseOrdine $fase, array $input, User $operatore): void
    {
        $modalita = (string) ($input['modalita'] ?? 'produzione');

        if ($modalita === 'stock') {
            $this->workflow->completaDaStockMultiLotto($fase, $this->lottiStockDaInput($fase, $input), $operatore);

            return;
        }

        $this->produci($fase, $input, $operatore);
    }

    /**
     * Normalizza i lotti "da stock" dall'input: formato multi-lotto `lotti_stock = [{lotto,quantita}]`.
     * Backward-compat: `lotto_stock` singolo (quantita' = quantita_prodotta indicata, o pianificata).
     *
     * @param  array<string,mixed>  $input
     * @return list<array{lotto:string, quantita:float}>
     */
    private function lottiStockDaInput(FaseOrdine $fase, array $input): array
    {
        $lotti = [];
        foreach ((array) ($input['lotti_stock'] ?? []) as $r) {
            $l = trim((string) ($r['lotto'] ?? ''));
            if ($l !== '') {
                $lotti[] = ['lotto' => $l, 'quantita' => (float) ($r['quantita'] ?? 0)];
            }
        }

        if ($lotti === [] && trim((string) ($input['lotto_stock'] ?? '')) !== '') {
            $q = isset($input['quantita_prodotta']) && $input['quantita_prodotta'] !== null
                ? (float) $input['quantita_prodotta']
                : (float) $fase->quantita_pianificata;
            $lotti[] = ['lotto' => trim((string) $input['lotto_stock']), 'quantita' => $q];
        }

        return $lotti;
    }

    /** Messaggio d'errore contestualizzato alla fase. */
    private function contestualizza(FaseOrdine $fase, WorkflowException $e): string
    {
        return sprintf(
            'Fase %s%s: %s',
            $fase->articolo_prodotto_codice,
            $fase->descrizione ? " ({$fase->descrizione})" : '',
            $e->getMessage(),
        );
    }

    /**
     * Produce una fase in quest'ordine: avvia gli step, conferma i materiali (con lotti/propagazione),
     * chiude gli step con lotto prodotto, e — se nodo condiviso — registra lo split con le quote
     * pianificate per sbloccare i padri.
     *
     * @param array<string,mixed> $input
     */
    private function produci(FaseOrdine $fase, array $input, User $operatore): void
    {
        $steps = $fase->steps()->orderBy('ordine')->get();

        // Articolo non configurato a reparto/tipo-fase => nessuno step. In chiusura massiva backoffice
        // la fase va comunque chiusa (registrando consumi + lotto in uscita), altrimenti resterebbe
        // aperta in silenzio e l'ordine non si completerebbe mai (§8). Chiusura diretta senza step.
        if ($steps->isEmpty()) {
            $this->confermaMateriali($fase, $input, $operatore);
            $this->workflow->chiudiFaseDiretta(
                $fase,
                $input['lotto_prodotto'] ?? null,
                isset($input['quantita_prodotta']) && $input['quantita_prodotta'] !== null
                    ? (float) $input['quantita_prodotta']
                    : null,
                $operatore,
            );
            $this->splitSeCondiviso($fase, $operatore);

            return;
        }

        $ultimoStepId = $steps->last()?->id;

        foreach ($steps as $step) {
            /** @var FaseOrdineStep $step */
            $this->workflow->avvia($step, $operatore);

            if ($step->consuma_materiali) {
                $this->confermaMateriali($fase, $input, $operatore);
            }

            $eUltimo = $step->id === $ultimoStepId;
            $this->workflow->chiudiStep(
                $step,
                $operatore,
                $eUltimo && isset($input['quantita_prodotta']) && $input['quantita_prodotta'] !== null
                    ? (float) $input['quantita_prodotta']
                    : null,
                $eUltimo ? ($input['lotto_prodotto'] ?? null) : null,
            );
        }

        $this->splitSeCondiviso($fase, $operatore);
    }

    /**
     * Conferma tutti i materiali della fase secondo l'input (quantita', lotti, forzatura tolleranza),
     * con auto-propagazione del lotto semilavorato dalla fase produttrice (§5.3) quando non fornito.
     *
     * @param array<string,mixed> $input
     */
    private function confermaMateriali(FaseOrdine $fase, array $input, User $operatore): void
    {
        $materialiInput = [];
        foreach ((array) ($input['materiali'] ?? []) as $m) {
            if (isset($m['materiale_id'])) {
                $materialiInput[(int) $m['materiale_id']] = $m;
            }
        }

        foreach ($fase->materiali as $materiale) {
            $mi = $materialiInput[$materiale->id] ?? null;
            $lotti = (array) ($mi['lotti'] ?? []);
            $conferma = (bool) ($mi['conferma_superamento'] ?? false);
            $qta = ($mi !== null && isset($mi['quantita_effettiva']) && $mi['quantita_effettiva'] !== null)
                ? (float) $mi['quantita_effettiva']
                : (float) $materiale->quantita_pianificata;

            // Auto-propagazione lotto semilavorato se non fornito (§5.3): dalla fase produttrice
            // (gia' chiusa grazie all'ordine bottom-up), a prescindere dal flag lotto. Resta
            // comunque sovrascrivibile via input.
            if ($materiale->e_semilavorato && $lotti === [] && $materiale->fase_produttrice_id) {
                $lp = LottoProdotto::where('fase_ordine_id', $materiale->fase_produttrice_id)->first();
                if ($lp !== null) {
                    $lotti = [['lotto' => $lp->lotto, 'quantita' => $qta]];
                }
            }

            // Se sono presenti righe lotto, la quantita' confermata = somma dei lotti.
            if ($lotti !== []) {
                $qta = array_sum(array_map(fn ($l) => (float) ($l['quantita'] ?? 0), $lotti));
            }

            $this->workflow->confermaMateriale($materiale, $qta, $operatore, $lotti, null, $conferma);
        }
    }

    /**
     * Nodo condiviso: registra lo split con le quote pianificate (riscalate sulla quantita' prodotta)
     * per sbloccare le fasi padre (§5-bis). No-op sulle fasi non condivise o gia' ripartite.
     */
    private function splitSeCondiviso(FaseOrdine $fase, User $operatore): void
    {
        $fase->refresh();
        if (! $fase->is_nodo_condiviso || $fase->stato !== StatoFase::Chiusa || $fase->split_completato) {
            return;
        }

        $assegnazioni = [];
        foreach ($this->splitService->destinazioni($fase) as $d) {
            $assegnazioni[$d['fase']->id] = (float) $d['quota_suggerita'];
        }
        $sommaQuote = array_sum($assegnazioni);
        $prodotta = $this->splitService->quantitaDaRipartire($fase);
        if ($sommaQuote > 0 && abs($sommaQuote - $prodotta) > 1e-9) {
            $fattore = $prodotta / $sommaQuote;
            foreach ($assegnazioni as $k => $v) {
                $assegnazioni[$k] = $v * $fattore;
            }
        }
        if ($assegnazioni !== []) {
            $this->splitService->registra($fase, $assegnazioni, $operatore);
        }
    }

    /**
     * Ordinamento topologico bottom-up: una fase compare DOPO tutte le sue fasi figlie (§3).
     *
     * @param Collection<int,FaseOrdine> $fasi
     * @return list<int>
     */
    private function ordineBottomUp(Collection $fasi): array
    {
        $figli = [];
        foreach ($fasi as $f) {
            $figli[$f->id] = $f->fasiFiglie->pluck('id')->all();
        }

        $risolte = [];
        $inCorso = [];
        $ordine = [];

        $visita = function (int $id) use (&$visita, &$risolte, &$inCorso, &$ordine, $figli): void {
            if (isset($risolte[$id])) {
                return;
            }
            $inCorso[$id] = true;
            foreach ($figli[$id] ?? [] as $figlioId) {
                // Solo figli presenti nell'ordine ed evitando cicli (non attesi in un DAG).
                if (isset($figli[$figlioId]) && ! isset($inCorso[$figlioId])) {
                    $visita((int) $figlioId);
                }
            }
            $risolte[$id] = true;
            $ordine[] = $id;
        };

        foreach (array_keys($figli) as $id) {
            $visita((int) $id);
        }

        return $ordine;
    }
}
