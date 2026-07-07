<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TipoFaseRequest;
use App\Models\Reparto;
use App\Models\TipoFase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD tipi fase con sequenza ordinata di step/reparti (fase multi-reparto, §3/§5).
 */
class TipoFaseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/TipiFase', [
            'tipiFase' => TipoFase::with('steps.reparto')->orderBy('codice')->get()->map(fn (TipoFase $t) => [
                'id' => $t->id,
                'codice' => $t->codice,
                'descrizione' => $t->descrizione,
                'steps' => $t->steps->map(fn ($s) => [
                    'reparto_id' => $s->reparto_id,
                    'reparto' => $s->reparto?->descrizione,
                    'ordine' => $s->ordine,
                    'descrizione' => $s->descrizione,
                    'consuma_materiali' => $s->consuma_materiali,
                ])->values(),
            ]),
            'reparti' => Reparto::where('attivo', true)->orderBy('descrizione')->get(['id', 'descrizione']),
        ]);
    }

    public function store(TipoFaseRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $tipo = TipoFase::create($request->only('codice', 'descrizione'));
            $this->salvaStep($tipo, $request->validated('steps'));
        });

        return back()->with('success', 'Tipo fase creato.');
    }

    public function update(TipoFaseRequest $request, TipoFase $tipoFase): RedirectResponse
    {
        DB::transaction(function () use ($request, $tipoFase) {
            $tipoFase->update($request->only('codice', 'descrizione'));
            $tipoFase->steps()->delete();
            $this->salvaStep($tipoFase, $request->validated('steps'));
        });

        return back()->with('success', 'Tipo fase aggiornato.');
    }

    public function destroy(TipoFase $tipoFase): RedirectResponse
    {
        // Gli step vanno in cascade; i riferimenti in articolo_config/fasi_ordine sono nullOnDelete.
        $tipoFase->delete();

        return back()->with('success', 'Tipo fase eliminato.');
    }

    /**
     * L'ordine degli step segue l'ordine dell'array (evita conflitti sull'unique ordine).
     *
     * @param array<int,array<string,mixed>> $steps
     */
    private function salvaStep(TipoFase $tipo, array $steps): void
    {
        foreach (array_values($steps) as $i => $step) {
            $tipo->steps()->create([
                'reparto_id' => $step['reparto_id'],
                'ordine' => $i + 1,
                'descrizione' => $step['descrizione'] ?? null,
                'consuma_materiali' => $step['consuma_materiali'] ?? ($i === 0),
            ]);
        }
    }
}
