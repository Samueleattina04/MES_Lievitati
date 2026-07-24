<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operatore;

use App\Enums\StatoFase;
use App\Http\Controllers\Controller;
use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\MaterialeFase;
use App\Produzione\FaseWorkflowService;
use App\Produzione\WorkflowException;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FifoAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interfaccia operatore (§8): coda di lavoro del reparto e schermata di esecuzione dello step
 * (avvio -> conferma materiali -> chiusura), con verifica giacenza mag. 06 e proposta lotti FIFO (§5).
 */
class EsecuzioneController extends Controller
{
    public function __construct(
        private readonly FaseWorkflowService $workflow,
        private readonly StockSourceAdapterInterface $stock,
    ) {}

    public function coda(Request $request): Response
    {
        $operatore = $request->user();
        // L'operatore vede solo i propri reparti; il backoffice vede tutti i reparti (change #1).
        $vincolato = $operatore->vincolatoAiReparti();
        $repartoIds = $operatore->reparti->pluck('id')->all();

        $steps = FaseOrdineStep::query()
            ->when($vincolato, fn ($q) => $q->whereIn('reparto_id', $repartoIds))
            ->where('stato', '!=', 'chiusa')
            ->with(['fase.ordine', 'fase.fasiFiglie', 'fase.steps', 'reparto'])
            ->get();

        $cards = $steps->map(function (FaseOrdineStep $step) {
            $lavorabile = $step->stato->value === 'in_corso' || $this->workflow->stepAvviabile($step);

            return [
                'step_id' => $step->id,
                'ordine_numero' => $step->fase->ordine->numero,
                'articolo' => $step->fase->articolo_prodotto_codice,
                'descrizione' => $step->fase->descrizione,
                'quantita' => $step->fase->quantita_pianificata,
                'udm' => $step->fase->udm,
                'reparto' => $step->reparto?->descrizione,
                'step_descrizione' => $step->descrizione,
                'stato' => $step->stato->value,
                'condiviso' => $step->fase->is_nodo_condiviso,
                'lavorabile' => $lavorabile,
                'motivo' => $lavorabile ? null : $this->workflow->motivoBlocco($step),
            ];
        });

        // In corso e lavorabili in cima; in attesa in fondo.
        $ordinati = $cards->sortBy(fn ($c) => [
            $c['stato'] === 'in_corso' ? 0 : ($c['lavorabile'] ? 1 : 2),
            $c['ordine_numero'],
        ])->values();

        // Ripartizioni in attesa: nodi condivisi chiusi ma non ancora ripartiti, prodotti in un
        // reparto dell'operatore (§5-bis). Vanno evase per sbloccare le fasi successive.
        $splitPendenti = FaseOrdine::query()
            ->where('is_nodo_condiviso', true)
            ->where('stato', StatoFase::Chiusa->value)
            ->where('split_completato', false)
            ->when($vincolato, fn ($q) => $q->whereHas('steps', fn ($q2) => $q2->whereIn('reparto_id', $repartoIds)))
            ->with('ordine')
            ->get()
            ->map(fn (FaseOrdine $f) => [
                'fase_id' => $f->id,
                'ordine_numero' => $f->ordine->numero,
                'articolo' => $f->articolo_prodotto_codice,
                'descrizione' => $f->descrizione,
                'quantita' => $f->quantita_prodotta ?? $f->quantita_pianificata,
                'udm' => $f->udm,
            ])->values();

        return Inertia::render('Operatore/Coda', [
            'operatore' => [
                'nome' => $operatore->name,
                'reparti' => $operatore->reparti->pluck('descrizione'),
                // Il backoffice non e' vincolato ai reparti: la UI lo segnala (change #1).
                'tutti_reparti' => ! $vincolato,
            ],
            'cards' => $ordinati,
            'splitPendenti' => $splitPendenti,
        ]);
    }

    public function show(Request $request, FaseOrdineStep $step): Response
    {
        $this->assicuraReparto($request, $step);
        $step->load([
            'fase.materiali.consumo.lotti',
            // Propagazione lotto semilavorato (§5.3): lotto della fase produttrice sulla riga-componente.
            'fase.materiali.faseProduttrice.lottiProdotto',
            'fase.steps.reparto',
            'fase.fasiFiglie:id,articolo_prodotto_codice',
            'fase.lottiProdotto',
            'reparto',
        ]);

        $fase = $step->fase;
        $lavorabile = $step->stato->value === 'in_corso' || $this->workflow->stepAvviabile($step);
        $articoloProdotto = \App\Models\Articolo::where('codice', $fase->articolo_prodotto_codice)->first();
        $richiedeLottoUscita = $articoloProdotto?->richiedeLotto() ?? false;
        $lottoUscita = $fase->lottiProdotto->first();
        // Prelievo da stock (§5.3): ogni fase e' un nodo prodotto, quindi e' prelevabile da stock
        // finche' non e' avviata, a prescindere dal flag lotto della sua anagrafica.
        $permettiDaStock = $fase->stato->value === 'da_lavorare';

        return Inertia::render('Operatore/Fase', [
            'step' => [
                'id' => $step->id,
                'ordine' => $step->ordine,
                'descrizione' => $step->descrizione,
                'stato' => $step->stato->value,
                'reparto' => $step->reparto?->descrizione,
                'consuma_materiali' => $step->consuma_materiali,
                'lavorabile' => $lavorabile,
                'motivo' => $lavorabile ? null : $this->workflow->motivoBlocco($step),
            ],
            'fase' => [
                'id' => $fase->id,
                'articolo' => $fase->articolo_prodotto_codice,
                'descrizione' => $fase->descrizione,
                'quantita' => $fase->quantita_pianificata,
                'udm' => $fase->udm,
                'stato' => $fase->stato->value,
                'condiviso' => $fase->is_nodo_condiviso,
                'ordine_numero' => $fase->ordine->numero ?? null,
                'richiede_lotto_uscita' => $richiedeLottoUscita,
                'lotto_uscita' => $lottoUscita?->lotto,
                'completata_da_stock' => $fase->completata_da_stock,
                'permetti_da_stock' => $permettiDaStock,
                'steps' => $fase->steps->map(fn ($s) => [
                    'reparto' => $s->reparto?->descrizione,
                    'ordine' => $s->ordine,
                    'stato' => $s->stato->value,
                ])->values(),
            ],
            'materiali' => $fase->materiali->map(fn (MaterialeFase $m) => $this->materialePerUi($m))->values(),
        ]);
    }

    /**
     * Prepara la riga materiale per la UI, arricchita con giacenza mag. 06 e proposta lotti FIFO (§5).
     *
     * @return array<string,mixed>
     */
    private function materialePerUi(MaterialeFase $m): array
    {
        // I semilavorati non sono verificati sul mag. 06 (§5): giacenza n/d, nessuna proposta FIFO.
        $verificaStock = ! $m->e_semilavorato;
        $giacenza = $verificaStock ? $this->stock->giacenzaArticolo($m->articolo_codice) : null;

        // Per i materiali a lotto (materie prime): lotti mag. 06 (per FIFO e per riconoscere i lotti
        // manuali) e giacenza TOTALE nota (tutti i magazzini) per l'avviso soft sui lotti manuali.
        $proposta = [];
        $lottiMag06 = [];
        $lottiDisponibili = [];
        $giacenzaTotale = null;
        $lottoPropagato = null;

        if ($m->e_semilavorato) {
            // Propagazione verso l'alto (§5.3, change #2): il lotto della fase produttrice viene
            // riportato sulla riga-componente, pre-compilato ma modificabile. Vale SEMPRE, a
            // prescindere dal flag lotto dell'anagrafica: un semilavorato prodotto ha comunque un lotto.
            $lottoPropagato = $m->faseProduttrice?->lottiProdotto->first()?->lotto;
            if ($m->consumo === null && $lottoPropagato !== null && $lottoPropagato !== '') {
                $proposta = [['lotto' => $lottoPropagato, 'quantita' => (float) $m->quantita_pianificata]];
            }
        } elseif ($m->flag_lotto) {
            // Materia prima a lotto: proposta FIFO e lotti disponibili dal mag. 06.
            $disponibili = $this->stock->lottiDisponibiliFifo($m->articolo_codice);
            $lottiMag06 = array_values(array_unique(array_map(fn ($l) => $l->lotto, $disponibili)));
            $lottiDisponibili = $this->aggregaLottiDisponibili($disponibili);
            $giacenzaTotale = $this->stock->giacenzaTotale($m->articolo_codice);
            if ($m->consumo === null) {
                $proposta = FifoAllocator::proponi($disponibili, (float) $m->quantita_pianificata);
            }
        }

        return [
            'id' => $m->id,
            'articolo' => $m->articolo_codice,
            'descrizione' => $m->descrizione,
            'quantita_pianificata' => $m->quantita_pianificata,
            'udm' => $m->udm,
            'flag_lotto' => $m->flag_lotto,
            // La riga va gestita a lotto se l'articolo lo prevede OPPURE se e' un semilavorato
            // (che porta sempre il lotto della fase produttrice). La UI usa questo flag.
            'gestione_lotto' => $m->flag_lotto || $m->e_semilavorato,
            'semilavorato' => $m->e_semilavorato,
            // Lotto ereditato dalla fase produttrice (solo per la nota UI; il valore e' gia' in proposta_fifo).
            'lotto_propagato' => $lottoPropagato,
            'confermato' => $m->consumo !== null,
            'quantita_effettiva' => $m->consumo?->quantita_effettiva,
            'giacenza_mag06' => $giacenza,
            'giacenza_totale' => $giacenzaTotale,
            'lotti_mag06' => $lottiMag06,
            // Lotti realmente disponibili sul mag. 06 (lotto + giacenza, ordine FIFO): la UI li mostra
            // come scelte rapide, cosi' l'utente puo' cambiare la proposta scegliendo un altro lotto.
            'lotti_disponibili' => $lottiDisponibili,
            'proposta_fifo' => $proposta,
            'lotti' => $m->consumo
                ? $m->consumo->lotti->map(fn ($l) => ['lotto' => $l->lotto, 'quantita' => $l->quantita])->values()
                : [],
        ];
    }

    /**
     * Aggrega i lotti disponibili sul mag. 06 per codice (somma le quantita'), preservando l'ordine
     * FIFO. Serve alla UI per mostrare/scegliere gli altri lotti utilizzabili.
     *
     * @param  list<\App\Stock\StockLotto>  $disponibili
     * @return list<array{lotto:string, quantita:float}>
     */
    private function aggregaLottiDisponibili(array $disponibili): array
    {
        $out = [];
        $idx = [];
        foreach ($disponibili as $l) {
            $k = 'k'.$l->lotto;
            if (isset($idx[$k])) {
                $out[$idx[$k]]['quantita'] += (float) $l->quantita;
            } else {
                $idx[$k] = count($out);
                $out[] = ['lotto' => (string) $l->lotto, 'quantita' => (float) $l->quantita];
            }
        }

        return $out;
    }

    public function avvia(Request $request, FaseOrdineStep $step): RedirectResponse
    {
        $this->assicuraReparto($request, $step);

        try {
            $this->workflow->avvia($step, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Fase avviata.');
    }

    public function confermaMateriale(Request $request, FaseOrdineStep $step, MaterialeFase $materiale): RedirectResponse
    {
        $this->assicuraReparto($request, $step);
        abort_unless($materiale->fase_ordine_id === $step->fase_ordine_id, 404);

        $dati = $request->validate([
            'quantita_effettiva' => ['required', 'numeric', 'min:0'],
            'lotti' => ['array'],
            'lotti.*.lotto' => ['nullable', 'string', 'max:100'],
            'lotti.*.quantita' => ['nullable', 'numeric', 'min:0'],
            // Conferma esplicita del superamento giacenza totale su lotto manuale (avviso non bloccante).
            'conferma_superamento' => ['boolean'],
        ]);

        try {
            $this->workflow->confermaMateriale(
                $materiale,
                (float) $dati['quantita_effettiva'],
                $request->user(),
                $dati['lotti'] ?? [],
                null,
                (bool) ($dati['conferma_superamento'] ?? false),
            );
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Materiale confermato.');
    }

    public function chiudi(Request $request, FaseOrdineStep $step): RedirectResponse
    {
        $this->assicuraReparto($request, $step);

        $dati = $request->validate([
            'quantita_prodotta' => ['nullable', 'numeric', 'min:0'],
            'lotto_prodotto' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $this->workflow->chiudiStep(
                $step,
                $request->user(),
                isset($dati['quantita_prodotta']) ? (float) $dati['quantita_prodotta'] : null,
                $dati['lotto_prodotto'] ?? null,
            );
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Se ho appena chiuso un nodo condiviso, serve la ripartizione prima di sbloccare i padri (§5-bis).
        $fase = $step->fase()->first();
        if ($fase->is_nodo_condiviso && $fase->stato === StatoFase::Chiusa && ! $fase->split_completato) {
            return redirect()->route('operatore.split', $fase->id)
                ->with('success', 'Fase chiusa. Ora ripartisci il semilavorato tra i prodotti.');
        }

        return redirect()->route('operatore.coda')->with('success', 'Step chiuso.');
    }

    /**
     * Completa la fase "da stock" indicando un lotto di semilavorato gia' esistente (§5.3, change #3):
     * la fase e' chiusa senza consumare i componenti (prelievo da stock).
     */
    public function completaDaStock(Request $request, FaseOrdineStep $step): RedirectResponse
    {
        $this->assicuraReparto($request, $step);

        $dati = $request->validate([
            'lotto' => ['required', 'string', 'max:100'],
        ]);

        try {
            $this->workflow->completaDaStock($step->fase, (string) $dati['lotto'], $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('operatore.coda')->with('success', 'Fase completata da stock (lotto esistente).');
    }

    /**
     * L'operatore puo' agire solo sugli step dei reparti a lui assegnati (§7). Il backoffice non e'
     * vincolato ai reparti (change #1): opera su qualunque fase/step.
     */
    private function assicuraReparto(Request $request, FaseOrdineStep $step): void
    {
        if (! $request->user()->vincolatoAiReparti()) {
            return;
        }

        $repartoIds = $request->user()->reparti->pluck('id')->all();
        abort_unless(in_array($step->reparto_id, $repartoIds, true), 403, 'Reparto non assegnato.');
    }
}
