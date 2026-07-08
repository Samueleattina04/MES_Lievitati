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
        $repartoIds = $operatore->reparti->pluck('id')->all();

        $steps = FaseOrdineStep::query()
            ->whereIn('reparto_id', $repartoIds)
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
            ->whereHas('steps', fn ($q) => $q->whereIn('reparto_id', $repartoIds))
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
            'operatore' => ['nome' => $operatore->name, 'reparti' => $operatore->reparti->pluck('descrizione')],
            'cards' => $ordinati,
            'splitPendenti' => $splitPendenti,
        ]);
    }

    public function show(Request $request, FaseOrdineStep $step): Response
    {
        $this->assicuraReparto($request, $step);
        $step->load(['fase.materiali.consumo.lotti', 'fase.steps.reparto', 'fase.fasiFiglie:id,articolo_prodotto_codice', 'fase.lottiProdotto', 'reparto']);

        $fase = $step->fase;
        $lavorabile = $step->stato->value === 'in_corso' || $this->workflow->stepAvviabile($step);
        $articoloProdotto = \App\Models\Articolo::where('codice', $fase->articolo_prodotto_codice)->first();
        $lottoUscita = $fase->lottiProdotto->first();

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
                'richiede_lotto_uscita' => $articoloProdotto?->richiedeLotto() ?? false,
                'lotto_uscita' => $lottoUscita?->lotto,
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
        // I semilavorati non sono verificati sul mag. 06 (§5): giacenza n/d, nessuna proposta lotti.
        $verificaStock = ! $m->e_semilavorato;
        $giacenza = $verificaStock ? $this->stock->giacenzaArticolo($m->articolo_codice) : null;

        // Proposta FIFO pre-compilata per i materiali a lotto (raw). Se gia' confermato, mostra i lotti reali.
        $proposta = [];
        if ($verificaStock && $m->flag_lotto && $m->consumo === null) {
            $proposta = FifoAllocator::proponi(
                $this->stock->lottiDisponibiliFifo($m->articolo_codice),
                (float) $m->quantita_pianificata,
            );
        }

        return [
            'id' => $m->id,
            'articolo' => $m->articolo_codice,
            'descrizione' => $m->descrizione,
            'quantita_pianificata' => $m->quantita_pianificata,
            'udm' => $m->udm,
            'flag_lotto' => $m->flag_lotto,
            'semilavorato' => $m->e_semilavorato,
            'confermato' => $m->consumo !== null,
            'quantita_effettiva' => $m->consumo?->quantita_effettiva,
            'giacenza_mag06' => $giacenza,
            'proposta_fifo' => $proposta,
            'lotti' => $m->consumo
                ? $m->consumo->lotti->map(fn ($l) => ['lotto' => $l->lotto, 'quantita' => $l->quantita])->values()
                : [],
        ];
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
        ]);

        try {
            $this->workflow->confermaMateriale(
                $materiale,
                (float) $dati['quantita_effettiva'],
                $request->user(),
                $dati['lotti'] ?? [],
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

    /** L'operatore puo' agire solo sugli step dei reparti a lui assegnati (§7). */
    private function assicuraReparto(Request $request, FaseOrdineStep $step): void
    {
        $repartoIds = $request->user()->reparti->pluck('id')->all();
        abort_unless(in_array($step->reparto_id, $repartoIds, true), 403, 'Reparto non assegnato.');
    }
}
