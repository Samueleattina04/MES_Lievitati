<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consente l'accesso alla rotta solo agli utenti con uno dei ruoli indicati (§7).
 * Uso:  ->middleware('ruolo:pianificazione,admin')
 */
class EnsureRuolo
{
    public function handle(Request $request, Closure $next, string ...$ruoli): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->ruolo?->value, $ruoli, true)) {
            abort(403, 'Non hai i permessi per accedere a questa sezione.');
        }

        return $next($request);
    }
}
