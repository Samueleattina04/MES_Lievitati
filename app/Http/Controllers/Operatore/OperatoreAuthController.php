<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operatore;

use App\Enums\RuoloUtente;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LogEventi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login rapido operatore via PIN numerico su tablet condiviso (§7). Il PIN viaggia solo hashato;
 * il login e' protetto da rate limiting per evitare tentativi ripetuti (§11).
 */
class OperatoreAuthController extends Controller
{
    public function showLogin(Request $request): Response|RedirectResponse
    {
        // Operatore gia' loggato o backoffice (che accede senza PIN): dritto alla coda (change #1).
        if ($request->user()?->puoAvanzareProduzione()) {
            return redirect()->route('operatore.coda');
        }

        return Inertia::render('Operatore/Login', [
            'lunghezzaMin' => config('mes.pin.min_length'),
            'lunghezzaMax' => config('mes.pin.max_length'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'pin' => ['required', 'string', 'min:'.config('mes.pin.min_length'), 'max:'.config('mes.pin.max_length')],
        ]);

        $chiave = 'pin-login:'.$request->ip();
        $max = (int) config('mes.pin.max_tentativi', 5);

        if (RateLimiter::tooManyAttempts($chiave, $max)) {
            $secondi = RateLimiter::availableIn($chiave);
            throw ValidationException::withMessages([
                'pin' => "Troppi tentativi. Riprova tra {$secondi} secondi.",
            ]);
        }

        $operatore = $this->trovaOperatorePerPin((string) $request->input('pin'));

        if ($operatore === null) {
            RateLimiter::hit($chiave, (int) config('mes.pin.decay_secondi', 60));
            throw ValidationException::withMessages(['pin' => 'PIN non valido.']);
        }

        RateLimiter::clear($chiave);
        Auth::login($operatore);
        $request->session()->regenerate();

        LogEventi::registra('operatore_login', $operatore, $operatore->id);

        return redirect()->intended(route('operatore.coda'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('operatore.login');
    }

    /**
     * Cerca un operatore attivo il cui PIN corrisponde. Numero operatori atteso: decine (§11),
     * quindi il confronto hashato per ciascuno e' accettabile ed evita di esporre i PIN.
     */
    private function trovaOperatorePerPin(string $pin): ?User
    {
        return User::query()
            ->where('ruolo', RuoloUtente::Operatore->value)
            ->where('attivo', true)
            ->whereNotNull('pin_hash')
            ->get()
            ->first(fn (User $u) => Hash::check($pin, (string) $u->pin_hash));
    }
}
