<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Http\Requests\StoreOrdineRequest;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Ordini\OrdineProduzioneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class OrdineController extends Controller
{
    public function __construct(
        private readonly OrdineProduzioneService $service,
        private readonly BomSourceAdapterInterface $adapter,
    ) {}

    public function index(): Response
    {
        $ordini = OrdineProduzione::query()
            ->withCount([
                'fasi',
                'fasi as fasi_chiuse_count' => fn ($q) => $q->where('stato', 'chiusa'),
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (OrdineProduzione $o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'articolo' => $o->articolo_finito_codice,
                'descrizione' => $o->descrizione_articolo,
                'quantita' => $o->quantita,
                'udm' => $o->udm,
                'data' => $o->data?->toDateString(),
                'stato' => $o->stato->value,
                'stato_label' => $o->stato->label(),
                'fasi' => $o->fasi_count,
                'fasi_chiuse' => $o->fasi_chiuse_count,
            ]);

        return Inertia::render('Ordini/Index', ['ordini' => $ordini]);
    }

    public function create(): Response
    {
        return Inertia::render('Ordini/Create');
    }

    public function store(StoreOrdineRequest $request): RedirectResponse
    {
        try {
            $ordine = $this->service->creaManuale([
                ...$request->validated(),
                'creato_da_id' => $request->user()->id,
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('ordini.show', $ordine)
            ->with('success', "Ordine {$ordine->numero} creato: generate {$ordine->fasi()->count()} fasi.");
    }

    public function show(OrdineProduzione $ordine): Response
    {
        $ordine->load([
            'fasi' => fn ($q) => $q->orderByDesc('livello_relativo')->orderBy('articolo_prodotto_codice'),
            'fasi.materiali',
            'fasi.steps.reparto',
            'fasi.repartoCorrente',
            'fasi.fasiFiglie:id,articolo_prodotto_codice',
        ]);

        return Inertia::render('Ordini/Show', [
            'ordine' => [
                'id' => $ordine->id,
                'numero' => $ordine->numero,
                'articolo' => $ordine->articolo_finito_codice,
                'descrizione' => $ordine->descrizione_articolo,
                'quantita' => $ordine->quantita,
                'udm' => $ordine->udm,
                'data' => $ordine->data?->toDateString(),
                'stato' => $ordine->stato->value,
                'stato_label' => $ordine->stato->label(),
                'note' => $ordine->note,
            ],
            'fasi' => $ordine->fasi->map(fn (FaseOrdine $f) => [
                'id' => $f->id,
                'articolo' => $f->articolo_prodotto_codice,
                'descrizione' => $f->descrizione,
                'quantita' => $f->quantita_pianificata,
                'udm' => $f->udm,
                'livello' => $f->livello_relativo,
                'stato' => $f->stato->value,
                'stato_label' => $f->stato->label(),
                'condiviso' => $f->is_nodo_condiviso,
                'reparto' => $f->repartoCorrente?->descrizione,
                'steps' => $f->steps->map(fn ($s) => [
                    'reparto' => $s->reparto?->descrizione,
                    'ordine' => $s->ordine,
                    'stato' => $s->stato->value,
                ])->values(),
                'materiali' => $f->materiali->map(fn ($m) => [
                    'articolo' => $m->articolo_codice,
                    'descrizione' => $m->descrizione,
                    'quantita' => $m->quantita_pianificata,
                    'udm' => $m->udm,
                    'flag_lotto' => $m->flag_lotto,
                    'semilavorato' => $m->e_semilavorato,
                ])->values(),
                'dipende_da' => $f->fasiFiglie->pluck('articolo_prodotto_codice')->values(),
            ])->values(),
        ]);
    }

    /**
     * Autocomplete articoli producibili per la creazione ordine (interroga la sorgente distinte).
     */
    public function cercaArticoli(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');

        if (mb_strlen(trim($query)) < 1) {
            return response()->json([]);
        }

        return response()->json($this->adapter->cercaArticoli($query, 25));
    }
}
