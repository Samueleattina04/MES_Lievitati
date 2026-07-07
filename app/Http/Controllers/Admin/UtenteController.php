<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RuoloUtente;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UtenteRequest;
use App\Models\Reparto;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD utenti (admin, §7). Creazione differenziata:
 *  - staff (admin/pianificazione/backoffice): email + password + ruolo;
 *  - operatore: nome + PIN numerico (unico, hashato) + reparti abilitati (no email/password).
 */
class UtenteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Utenti', [
            'utenti' => User::with('reparti:id')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'ruolo' => $u->ruolo?->value,
                'ruolo_label' => $u->ruolo?->label(),
                'attivo' => $u->attivo,
                'ha_pin' => $u->pin_hash !== null,
                'reparti' => $u->reparti->pluck('id'),
            ]),
            'reparti' => Reparto::where('attivo', true)->orderBy('descrizione')->get(['id', 'descrizione']),
            'ruoli' => collect(RuoloUtente::cases())->map(fn (RuoloUtente $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'usa_pin' => $r->usaPin(),
            ]),
        ]);
    }

    public function store(UtenteRequest $request): RedirectResponse
    {
        $this->salva($request, new User());

        return back()->with('success', 'Utente creato.');
    }

    public function update(UtenteRequest $request, User $utente): RedirectResponse
    {
        $this->salva($request, $utente);

        return back()->with('success', 'Utente aggiornato.');
    }

    public function destroy(Request $request, User $utente): RedirectResponse
    {
        if ($utente->id === $request->user()->id) {
            return back()->with('error', 'Non puoi eliminare il tuo stesso utente.');
        }

        $utente->delete();

        return back()->with('success', 'Utente eliminato.');
    }

    private function salva(UtenteRequest $request, User $utente): void
    {
        $ruolo = RuoloUtente::from((string) $request->input('ruolo'));

        $utente->name = (string) $request->input('name');
        $utente->ruolo = $ruolo;
        $utente->attivo = $request->has('attivo') ? $request->boolean('attivo') : true;

        if ($ruolo === RuoloUtente::Operatore) {
            $utente->email = null;
            $utente->password = null;            // cast 'hashed' gestisce null
            if ($request->filled('pin')) {
                $utente->pin_hash = Hash::make((string) $request->input('pin'));
            }
            $utente->save();
            $utente->reparti()->sync($request->input('reparti', []));
        } else {
            $utente->email = (string) $request->input('email');
            $utente->pin_hash = null;
            if ($request->filled('password')) {
                $utente->password = (string) $request->input('password'); // cast 'hashed' esegue l'hash
            }
            $utente->email_verified_at ??= now();
            $utente->save();
            $utente->reparti()->detach();
        }
    }
}
