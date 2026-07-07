<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RepartoRequest;
use App\Models\Reparto;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD reparti (admin, §5).
 */
class RepartoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Reparti', [
            'reparti' => Reparto::orderBy('codice')->get()->map(fn (Reparto $r) => [
                'id' => $r->id,
                'codice' => $r->codice,
                'descrizione' => $r->descrizione,
                'attivo' => $r->attivo,
            ]),
        ]);
    }

    public function store(RepartoRequest $request): RedirectResponse
    {
        Reparto::create($request->validated());

        return back()->with('success', 'Reparto creato.');
    }

    public function update(RepartoRequest $request, Reparto $reparto): RedirectResponse
    {
        $reparto->update($request->validated());

        return back()->with('success', 'Reparto aggiornato.');
    }

    public function destroy(Reparto $reparto): RedirectResponse
    {
        try {
            $reparto->delete();
        } catch (QueryException) {
            return back()->with('error', 'Reparto in uso in una fase o tipo fase: disattivalo invece di eliminarlo.');
        }

        return back()->with('success', 'Reparto eliminato.');
    }
}
