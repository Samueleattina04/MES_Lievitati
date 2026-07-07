<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticoloConfigurazioneMes;
use App\Models\Reparto;
use App\Models\TipoFase;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing dell'area amministrazione (solo ruolo Admin, §7).
 */
class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Index', [
            'conteggi' => [
                'reparti' => Reparto::count(),
                'tipi_fase' => TipoFase::count(),
                'configurazioni' => ArticoloConfigurazioneMes::count(),
                'utenti' => User::count(),
            ],
        ]);
    }
}
