<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operatore;

use App\Enums\StatoFase;
use App\Http\Controllers\Controller;
use App\Models\FaseOrdine;
use App\Produzione\SplitService;
use App\Produzione\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Schermata di ripartizione (split) di un nodo condiviso (§5-bis, §8): compare dopo la chiusura
 * della fase condivisa e sblocca le fasi padre che la consumano.
 */
class SplitController extends Controller
{
    public function __construct(
        private readonly SplitService $splitService,
    ) {}

    public function show(Request $request, FaseOrdine $fase): Response|RedirectResponse
    {
        $this->assicuraReparto($request, $fase);

        if (! $fase->is_nodo_condiviso || $fase->stato !== StatoFase::Chiusa) {
            return redirect()->route('operatore.coda')->with('error', 'Ripartizione non applicabile a questa fase.');
        }
        if ($fase->split_completato) {
            return redirect()->route('operatore.coda')->with('success', 'Ripartizione gia registrata.');
        }

        $destinazioni = $this->splitService->destinazioni($fase)->map(fn (array $d) => [
            'fase_destinazione_id' => $d['fase']->id,
            'articolo' => $d['fase']->articolo_prodotto_codice,
            'descrizione' => $d['fase']->descrizione,
            'quota_suggerita' => $d['quota_suggerita'],
        ])->values();

        return Inertia::render('Operatore/Split', [
            'fase' => [
                'id' => $fase->id,
                'articolo' => $fase->articolo_prodotto_codice,
                'descrizione' => $fase->descrizione,
                'udm' => $fase->udm,
                'quantita_da_ripartire' => $this->splitService->quantitaDaRipartire($fase),
            ],
            'destinazioni' => $destinazioni,
        ]);
    }

    public function store(Request $request, FaseOrdine $fase): RedirectResponse
    {
        $this->assicuraReparto($request, $fase);

        $dati = $request->validate([
            'assegnazioni' => ['required', 'array', 'min:1'],
            'assegnazioni.*.fase_destinazione_id' => ['required', 'integer'],
            'assegnazioni.*.quantita' => ['required', 'numeric', 'min:0'],
        ]);

        $map = [];
        foreach ($dati['assegnazioni'] as $a) {
            $map[(int) $a['fase_destinazione_id']] = (float) $a['quantita'];
        }

        try {
            $this->splitService->registra($fase, $map, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('operatore.coda')->with('success', 'Ripartizione registrata: fasi successive sbloccate.');
    }

    /**
     * L'operatore deve appartenere a un reparto che ha prodotto questa fase. Il backoffice non e'
     * vincolato ai reparti (change #1): puo' ripartire qualunque nodo condiviso.
     */
    private function assicuraReparto(Request $request, FaseOrdine $fase): void
    {
        if (! $request->user()->vincolatoAiReparti()) {
            return;
        }

        $repartiFase = $fase->steps()->pluck('reparto_id')->all();
        $repartiOperatore = $request->user()->reparti->pluck('id')->all();
        abort_if(empty(array_intersect($repartiFase, $repartiOperatore)), 403, 'Reparto non assegnato.');
    }
}
