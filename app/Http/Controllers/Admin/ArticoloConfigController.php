<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArticoloConfigRequest;
use App\Models\ArticoloConfigurazioneMes;
use App\Models\Reparto;
use App\Models\TipoFase;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD mappatura articolo prodotto -> reparto/tipo fase (§5). Permette al planner di sapere
 * in quale reparto lavorare ogni nodo prodotto; chiavata sul codice articolo (sopravvive alla cache).
 */
class ArticoloConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ArticoliConfig', [
            'configurazioni' => ArticoloConfigurazioneMes::with(['repartoDefault', 'tipoFase'])
                ->orderBy('articolo_codice')->get()->map(fn (ArticoloConfigurazioneMes $c) => [
                    'id' => $c->id,
                    'articolo_codice' => $c->articolo_codice,
                    'reparto_default_id' => $c->reparto_default_id,
                    'reparto' => $c->repartoDefault?->descrizione,
                    'tipo_fase_id' => $c->tipo_fase_id,
                    'tipo_fase' => $c->tipoFase?->codice,
                    'flag_lotto_override' => $c->flag_lotto_override,
                    'note' => $c->note,
                ]),
            'reparti' => Reparto::where('attivo', true)->orderBy('descrizione')->get(['id', 'descrizione']),
            'tipiFase' => TipoFase::orderBy('codice')->get(['id', 'codice', 'descrizione']),
        ]);
    }

    public function store(ArticoloConfigRequest $request): RedirectResponse
    {
        ArticoloConfigurazioneMes::create($request->validated());

        return back()->with('success', 'Configurazione articolo creata.');
    }

    public function update(ArticoloConfigRequest $request, ArticoloConfigurazioneMes $config): RedirectResponse
    {
        $config->update($request->validated());

        return back()->with('success', 'Configurazione articolo aggiornata.');
    }

    public function destroy(ArticoloConfigurazioneMes $config): RedirectResponse
    {
        $config->delete();

        return back()->with('success', 'Configurazione articolo eliminata.');
    }
}
