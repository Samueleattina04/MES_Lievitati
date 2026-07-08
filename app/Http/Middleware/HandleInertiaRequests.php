<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Ruolo esposto al frontend per il rendering condizionale delle sezioni (§7).
                'ruolo' => $user?->ruolo?->value,
                // Permessi (matrice ruolo): la UI mostra solo le sezioni consentite. La sicurezza
                // reale e' comunque lato server (Gate/middleware 'can:*').
                'can' => $user?->ruolo?->permessi() ?? [],
            ],
            // Messaggi flash per i toast/notifiche lato Inertia.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Token CSRF per i form POST non-Inertia (es. download export).
            'csrf_token' => fn () => csrf_token(),
        ];
    }
}
