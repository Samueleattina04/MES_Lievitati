<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatoFase;
use App\Enums\StatoOrdine;
use App\Export\EsportazioneService;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard di monitoraggio produzione (§9): stato ordini, avanzamento, carico per reparto,
 * colli di bottiglia (fasi ferme oltre soglia), tempi medi, scostamento ricetta, ordini pronti export.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $sogliaOre = (int) config('mes.fase_ferma_ore', 8);
        $limiteFerme = now()->subHours($sogliaOre);

        // Ordini per stato.
        $perStato = OrdineProduzione::select('stato', DB::raw('count(*) as n'))
            ->groupBy('stato')->pluck('n', 'stato');
        $conteggioStato = [];
        foreach (StatoOrdine::cases() as $s) {
            $conteggioStato[$s->value] = (int) ($perStato[$s->value] ?? 0);
        }

        // Fasi ferme (avviate da troppo tempo) = colli di bottiglia.
        $fasiFerme = FaseOrdine::with('ordine', 'repartoCorrente')
            ->where('stato', StatoFase::InCorso->value)
            ->whereNotNull('timestamp_inizio')
            ->where('timestamp_inizio', '<=', $limiteFerme)
            ->orderBy('timestamp_inizio')
            ->limit(50)
            ->get()
            ->map(fn (FaseOrdine $f) => [
                'ordine' => $f->ordine->numero ?? null,
                'articolo' => $f->articolo_prodotto_codice,
                'reparto' => $f->repartoCorrente?->descrizione,
                'da_ore' => $f->timestamp_inizio?->diffInHours(now()),
            ]);

        // Carico corrente per reparto: step non chiusi.
        $caricoReparto = DB::table('fase_ordine_step')
            ->join('reparti', 'reparti.id', '=', 'fase_ordine_step.reparto_id')
            ->where('fase_ordine_step.stato', '!=', StatoFase::Chiusa->value)
            ->groupBy('reparti.id', 'reparti.descrizione')
            ->select('reparti.descrizione', DB::raw('count(*) as n'))
            ->orderByDesc('n')
            ->get();

        // Tempo medio di lavorazione (minuti) per reparto, sugli step chiusi.
        $tempiReparto = DB::table('fase_ordine_step')
            ->join('reparti', 'reparti.id', '=', 'fase_ordine_step.reparto_id')
            ->where('fase_ordine_step.stato', StatoFase::Chiusa->value)
            ->whereNotNull('timestamp_inizio')->whereNotNull('timestamp_fine')
            ->groupBy('reparti.id', 'reparti.descrizione')
            ->select('reparti.descrizione', DB::raw('avg(timestampdiff(minute, timestamp_inizio, timestamp_fine)) as minuti'))
            ->get()
            ->map(fn ($r) => ['reparto' => $r->descrizione, 'minuti' => (int) round((float) $r->minuti)]);

        // Scostamento ricetta: % fasi chiuse con quantita' prodotta diversa dalla pianificata.
        $fasiChiuse = FaseOrdine::where('stato', StatoFase::Chiusa->value)->whereNotNull('quantita_prodotta')->count();
        $fasiScostate = FaseOrdine::where('stato', StatoFase::Chiusa->value)
            ->whereNotNull('quantita_prodotta')
            ->whereColumn('quantita_prodotta', '!=', 'quantita_pianificata')
            ->count();
        $percScostamento = $fasiChiuse > 0 ? round($fasiScostate / $fasiChiuse * 100, 1) : 0.0;

        // Ordini pronti per l'export: completati (mai esportati) + esportati (ri-scaricabili e ancora
        // esportabili verso l'altro gestionale). Cosi' i due bottoni restano disponibili dopo il primo.
        $prontiExport = OrdineProduzione::whereIn('stato', [StatoOrdine::Completato->value, StatoOrdine::Esportato->value])
            ->orderByDesc('data')
            ->limit(100)
            ->get(['id', 'numero', 'articolo_finito_codice', 'quantita', 'udm', 'data', 'stato', 'esportato_at'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'articolo' => $o->articolo_finito_codice,
                'quantita' => $o->quantita,
                'udm' => $o->udm,
                'data' => $o->data?->toDateString(),
                'esportato' => $o->stato === StatoOrdine::Esportato,
            ]);

        // Gestionali con un tracciato configurato: la UI abilita solo i bottoni corrispondenti.
        $gestionaliExport = app(EsportazioneService::class)->gestionaliConfigurati();

        return Inertia::render('Dashboard', [
            'kpi' => [
                'ordini_per_stato' => $conteggioStato,
                'fasi_in_corso' => (int) FaseOrdine::where('stato', StatoFase::InCorso->value)->count(),
                'fasi_ferme_count' => $fasiFerme->count(),
                'soglia_ore' => $sogliaOre,
                'perc_scostamento' => $percScostamento,
            ],
            'fasiFerme' => $fasiFerme,
            'caricoReparto' => $caricoReparto,
            'tempiReparto' => $tempiReparto,
            'prontiExport' => $prontiExport,
            'gestionaliExport' => $gestionaliExport,
        ]);
    }
}
