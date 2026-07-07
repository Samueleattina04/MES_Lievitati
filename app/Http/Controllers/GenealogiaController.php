<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Produzione\GenealogiaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consultazione della genealogia dei lotti (§6) per backoffice/pianificazione: dato un lotto,
 * mostra l'albero a ritroso (materie prime consumate) e in avanti (dove e' finito).
 */
class GenealogiaController extends Controller
{
    public function index(Request $request, GenealogiaService $genealogia): Response
    {
        $lotto = trim((string) $request->query('lotto', ''));

        return Inertia::render('Genealogia/Index', [
            'lotto' => $lotto,
            'aRitroso' => $lotto !== '' ? $genealogia->aRitroso($lotto) : null,
            'inAvanti' => $lotto !== '' ? $genealogia->inAvanti($lotto) : null,
        ]);
    }
}
