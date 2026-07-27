<?php

declare(strict_types=1);

namespace App\Http\Controllers\Produzione;

use App\Enums\StatoOrdine;
use App\Http\Controllers\Controller;
use App\Models\Articolo;
use App\Models\FaseOrdine;
use App\Models\MaterialeFase;
use App\Models\OrdineProduzione;
use App\Produzione\ChiusuraMassivaService;
use App\Produzione\WorkflowException;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FifoAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Area di avanzamento produzione dal backoffice (§7, §8, change #1/#4): elenco ordini da chiudere e
 * chiusura massiva dalla distinta esplosa. La chiusura guidata fase-per-fase riusa l'area operatore
 * (/operatore/coda), accessibile al backoffice senza vincolo di reparto.
 *
 * Accesso via gate 'avanzare-produzione' (operatore + backoffice); in pratica usata dal backoffice,
 * che ha il link in dashboard/nav (l'operatore lavora dai tablet nell'area /operatore).
 */
class ChiusuraController extends Controller
{
    public function __construct(
        private readonly StockSourceAdapterInterface $stock,
        private readonly ChiusuraMassivaService $chiusura,
    ) {}

    /** Elenco ordini ancora da chiudere (aperti / in lavorazione). */
    public function index(): Response
    {
        $ordini = OrdineProduzione::query()
            ->whereIn('stato', [StatoOrdine::Aperto->value, StatoOrdine::InLavorazione->value])
            ->withCount([
                'fasi',
                'fasi as fasi_chiuse_count' => fn ($q) => $q->where('stato', 'chiusa'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrdineProduzione $o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'articolo' => $o->articolo_finito_codice,
                'descrizione' => $o->descrizione_articolo,
                'quantita' => $o->quantita,
                'udm' => $o->udm,
                'stato' => $o->stato->value,
                'stato_label' => $o->stato->label(),
                'fasi' => $o->fasi_count,
                'fasi_chiuse' => $o->fasi_chiuse_count,
            ]);

        return Inertia::render('Produzione/Index', ['ordini' => $ordini]);
    }

    /** Vista di chiusura massiva: tutte le fasi dell'ordine, ordinate bottom-up. */
    public function chiusuraMassiva(OrdineProduzione $ordine): Response|RedirectResponse
    {
        if (in_array($ordine->stato, [StatoOrdine::Completato, StatoOrdine::Esportato], true)) {
            return redirect()->route('produzione.index')
                ->with('error', "Ordine {$ordine->numero} gia {$ordine->stato->label()}: chiusura non applicabile.");
        }

        $ordine->load([
            // Bottom-up: prima i figli (livello piu' profondo), poi i padri.
            'fasi' => fn ($q) => $q->orderByDesc('livello_relativo')->orderBy('articolo_prodotto_codice'),
            'fasi.materiali.consumo.lotti',
            'fasi.materiali.faseProduttrice.lottiProdotto',
            'fasi.steps.reparto',
            'fasi.lottiProdotto',
            'fasi.fasiFiglie:id,articolo_prodotto_codice',
        ]);

        // Cache anagrafica per sapere quali nodi prodotti richiedono un lotto in uscita.
        $articoli = Articolo::whereIn('codice', $ordine->fasi->pluck('articolo_prodotto_codice')->unique())
            ->get()->keyBy('codice');

        $fasi = $ordine->fasi->map(function (FaseOrdine $f) use ($articoli) {
            $richiedeLotto = $articoli->get($f->articolo_prodotto_codice)?->richiedeLotto() ?? false;

            return [
                'id' => $f->id,
                'articolo' => $f->articolo_prodotto_codice,
                'descrizione' => $f->descrizione,
                'quantita' => (float) $f->quantita_pianificata,
                'udm' => $f->udm,
                'livello' => $f->livello_relativo,
                'stato' => $f->stato->value,
                'condiviso' => $f->is_nodo_condiviso,
                'gia_chiusa' => $f->eChiusa(),
                'completata_da_stock' => $f->completata_da_stock,
                'richiede_lotto_uscita' => $richiedeLotto,
                // Ogni fase e' un nodo prodotto (ha una sua distinta): e' sempre prelevabile da stock,
                // a prescindere dal flag lotto della sua anagrafica (§5.3, change #3).
                'permetti_da_stock' => true,
                'lotti_stock' => $this->stock->lottiTuttiMagazzini($f->articolo_prodotto_codice),
                'lotto_uscita' => $f->lottiProdotto->first()?->lotto,
                'lotti_uscita' => $f->lottiProdotto
                    ->map(fn ($lp) => ['lotto' => $lp->lotto, 'quantita' => (float) $lp->quantita])
                    ->values(),
                'reparti' => $f->steps->map(fn ($s) => $s->reparto?->descrizione)->filter()->values(),
                'materiali' => $f->materiali->map(fn (MaterialeFase $m) => $this->materialePerUi($m))->values(),
            ];
        })->values();

        return Inertia::render('Produzione/ChiusuraMassiva', [
            'ordine' => [
                'id' => $ordine->id,
                'numero' => $ordine->numero,
                'articolo' => $ordine->articolo_finito_codice,
                'descrizione' => $ordine->descrizione_articolo,
                'quantita' => $ordine->quantita,
                'udm' => $ordine->udm,
                'stato' => $ordine->stato->value,
            ],
            'fasi' => $fasi,
        ]);
    }

    /** Esegue la chiusura massiva in blocco (transazionale). */
    public function chiudi(Request $request, OrdineProduzione $ordine): RedirectResponse
    {
        if (in_array($ordine->stato, [StatoOrdine::Completato, StatoOrdine::Esportato], true)) {
            return back()->with('error', "Ordine {$ordine->numero} gia {$ordine->stato->label()}: chiusura non applicabile.");
        }

        $dati = $request->validate([
            'fasi' => ['required', 'array', 'min:1'],
            'fasi.*.fase_id' => ['required', 'integer'],
            'fasi.*.modalita' => ['required', Rule::in(['produzione', 'stock'])],
            'fasi.*.lotto_prodotto' => ['nullable', 'string', 'max:100'],
            'fasi.*.lotto_stock' => ['nullable', 'string', 'max:100'],
            'fasi.*.lotti_stock' => ['array'],
            'fasi.*.lotti_stock.*.lotto' => ['nullable', 'string', 'max:100'],
            'fasi.*.lotti_stock.*.quantita' => ['nullable', 'numeric', 'min:0'],
            'fasi.*.quantita_prodotta' => ['nullable', 'numeric', 'min:0'],
            'fasi.*.materiali' => ['array'],
            'fasi.*.materiali.*.materiale_id' => ['required', 'integer'],
            'fasi.*.materiali.*.quantita_effettiva' => ['nullable', 'numeric', 'min:0'],
            'fasi.*.materiali.*.lotti' => ['array'],
            'fasi.*.materiali.*.lotti.*.lotto' => ['nullable', 'string', 'max:100'],
            'fasi.*.materiali.*.lotti.*.quantita' => ['nullable', 'numeric', 'min:0'],
            'fasi.*.materiali.*.conferma_superamento' => ['boolean'],
        ]);

        // Verifica che tutte le fasi appartengano a quest'ordine (nessuna manomissione dell'id).
        $idsOrdine = $ordine->fasi()->pluck('id')->all();
        $map = [];
        foreach ($dati['fasi'] as $f) {
            $faseId = (int) $f['fase_id'];
            if (! in_array($faseId, $idsOrdine, true)) {
                return back()->with('error', 'Una delle fasi non appartiene a questo ordine.');
            }
            $map[$faseId] = $f;
        }

        try {
            $this->chiusura->chiudiOrdine($ordine, $map, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        $ordine->refresh();
        $messaggio = $ordine->stato === StatoOrdine::Completato
            ? "Ordine {$ordine->numero} completato: tutte le fasi chiuse."
            : "Ordine {$ordine->numero} aggiornato.";

        return redirect()->route('produzione.index')->with('success', $messaggio);
    }

    /** Chiude una SINGOLA fase dell'ordine (bottone "Completa" per fase). */
    public function chiudiFase(Request $request, OrdineProduzione $ordine, FaseOrdine $fase): RedirectResponse
    {
        if (in_array($ordine->stato, [StatoOrdine::Completato, StatoOrdine::Esportato], true)) {
            return back()->with('error', "Ordine {$ordine->numero} gia {$ordine->stato->label()}: chiusura non applicabile.");
        }
        if ($fase->ordine_id !== $ordine->id) {
            return back()->with('error', 'La fase non appartiene a questo ordine.');
        }

        $dati = $request->validate([
            'modalita' => ['required', Rule::in(['produzione', 'stock'])],
            'lotto_prodotto' => ['nullable', 'string', 'max:100'],
            'lotto_stock' => ['nullable', 'string', 'max:100'],
            'lotti_stock' => ['array'],
            'lotti_stock.*.lotto' => ['nullable', 'string', 'max:100'],
            'lotti_stock.*.quantita' => ['nullable', 'numeric', 'min:0'],
            'quantita_prodotta' => ['nullable', 'numeric', 'min:0'],
            'materiali' => ['array'],
            'materiali.*.materiale_id' => ['required', 'integer'],
            'materiali.*.quantita_effettiva' => ['nullable', 'numeric', 'min:0'],
            'materiali.*.lotti' => ['array'],
            'materiali.*.lotti.*.lotto' => ['nullable', 'string', 'max:100'],
            'materiali.*.lotti.*.quantita' => ['nullable', 'numeric', 'min:0'],
            'materiali.*.conferma_superamento' => ['boolean'],
        ]);

        try {
            $this->chiusura->chiudiFase($ordine, $fase->id, $dati, $request->user());
        } catch (WorkflowException $e) {
            return back()->with('error', $e->getMessage());
        }

        $ordine->refresh();
        if ($ordine->stato === StatoOrdine::Completato) {
            return redirect()->route('produzione.index')
                ->with('success', "Ordine {$ordine->numero} completato: tutte le fasi chiuse.");
        }

        return back()->with('success', "Fase {$fase->articolo_prodotto_codice} chiusa.");
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

    /**
     * Prepara la riga materiale per la vista di chiusura massiva: proposta FIFO per le materie prime,
     * lotto propagato per i semilavorati (se la fase produttrice ha gia' un lotto), giacenza mag. 06.
     *
     * @return array<string,mixed>
     */
    private function materialePerUi(MaterialeFase $m): array
    {
        $verificaStock = ! $m->e_semilavorato;
        $proposta = [];
        $lottiMag06 = [];
        $lottiDisponibili = [];
        $giacenza = $verificaStock ? $this->stock->giacenzaArticolo($m->articolo_codice) : null;
        $giacenzaTotale = null;
        $lottoPropagato = null;

        if ($m->e_semilavorato) {
            // Propagazione (§5.3): il lotto della fase produttrice viene riportato sulla riga
            // (pre-compilato, modificabile). Vale SEMPRE, a prescindere dal flag lotto. Se la fase
            // produttrice non ha ancora prodotto, la propagazione completa avviene lato client.
            $lottoPropagato = $m->faseProduttrice?->lottiProdotto->first()?->lotto;
            if ($m->consumo === null && $lottoPropagato !== null && $lottoPropagato !== '') {
                $proposta = [['lotto' => $lottoPropagato, 'quantita' => (float) $m->quantita_pianificata]];
            }
        } elseif ($m->flag_lotto) {
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
            'quantita_pianificata' => (float) $m->quantita_pianificata,
            'udm' => $m->udm,
            'flag_lotto' => $m->flag_lotto,
            // Gestita a lotto se l'anagrafica lo prevede o se e' un semilavorato (lotto propagato).
            'gestione_lotto' => $m->flag_lotto || $m->e_semilavorato,
            'semilavorato' => $m->e_semilavorato,
            // Articolo della fase che produce questo semilavorato: la UI lo usa per propagare il lotto.
            'articolo_produttore' => $m->e_semilavorato ? $m->articolo_codice : null,
            'lotto_propagato' => $lottoPropagato,
            'giacenza_mag06' => $giacenza,
            'giacenza_totale' => $giacenzaTotale,
            'lotti_mag06' => $lottiMag06,
            // Lotti disponibili sul mag. 06 (lotto + giacenza, ordine FIFO) come scelte rapide.
            'lotti_disponibili' => $lottiDisponibili,
            'proposta_fifo' => $proposta,
            'confermato' => $m->consumo !== null,
            'lotti' => $m->consumo
                ? $m->consumo->lotti->map(fn ($l) => ['lotto' => $l->lotto, 'quantita' => (float) $l->quantita])->values()
                : [],
        ];
    }
}
