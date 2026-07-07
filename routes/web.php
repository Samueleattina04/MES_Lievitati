<?php

use App\Http\Controllers\Admin\ArticoloConfigController;
use App\Http\Controllers\Admin\HomeController as AdminHomeController;
use App\Http\Controllers\Admin\RepartoController;
use App\Http\Controllers\Admin\TipoFaseController;
use App\Http\Controllers\Admin\UtenteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EsportazioneController;
use App\Http\Controllers\GenealogiaController;
use App\Http\Controllers\OrdineController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Operatore\EsecuzioneController;
use App\Http\Controllers\Operatore\OperatoreAuthController;
use App\Http\Controllers\Operatore\SplitController;
use App\Http\Controllers\Operatore\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();
    if ($user === null) {
        return redirect()->route('login');
    }

    return $user->eOperatore()
        ? redirect()->route('operatore.coda')
        : redirect()->route('dashboard');
});

/*
| Area operatore (§7, §8): login rapido via PIN su tablet condiviso e coda di lavoro di reparto.
*/
Route::prefix('operatore')->name('operatore.')->group(function () {
    Route::get('login', [OperatoreAuthController::class, 'showLogin'])->name('login');
    // Il rate limiting fine (per IP, con messaggi) e' gestito nel controller; qui un guard generico.
    Route::post('pin-login', [OperatoreAuthController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('pin-login');

    Route::middleware(['auth', 'ruolo:operatore'])->group(function () {
        Route::post('logout', [OperatoreAuthController::class, 'logout'])->name('logout');
        Route::get('/', [EsecuzioneController::class, 'coda'])->name('coda');
        Route::get('step/{step}', [EsecuzioneController::class, 'show'])->name('fase');
        Route::post('step/{step}/avvia', [EsecuzioneController::class, 'avvia'])->name('step.avvia');
        Route::post('step/{step}/materiale/{materiale}/conferma', [EsecuzioneController::class, 'confermaMateriale'])->name('materiale.conferma');
        Route::post('step/{step}/chiudi', [EsecuzioneController::class, 'chiudi'])->name('step.chiudi');

        // Ripartizione (split) di un nodo condiviso (§5-bis).
        Route::get('split/{fase}', [SplitController::class, 'show'])->name('split');
        Route::post('split/{fase}', [SplitController::class, 'store'])->name('split.store');
    });
});

// Sincronizzazione coda offline (§8): endpoint /api/sync idempotente, per online e replay.
Route::middleware(['auth', 'ruolo:operatore'])
    ->post('/api/sync', [SyncController::class, 'store'])
    ->name('operatore.sync');

/*
| Area backoffice/pianificazione/admin (email + password).
*/
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('ruolo:backoffice,pianificazione,admin')
        ->name('dashboard');

    // Genealogia lotti (§6): backoffice + pianificazione.
    Route::get('/genealogia', [GenealogiaController::class, 'index'])
        ->middleware('ruolo:backoffice,pianificazione,admin')
        ->name('genealogia.index');

    // Export tracciati (§10): backoffice di produzione (+ admin). Marca l'ordine "esportato".
    Route::post('/export/{ordine}', [EsportazioneController::class, 'esporta'])
        ->middleware('ruolo:backoffice,admin')
        ->name('export.esporta');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Ordini di produzione (§9). Rotte statiche prima di /ordini/{ordine}.
    Route::middleware('ruolo:pianificazione,admin,backoffice')->group(function () {
        Route::get('/ordini', [OrdineController::class, 'index'])->name('ordini.index');
        Route::get('/ordini/ricerca-articoli', [OrdineController::class, 'cercaArticoli'])->name('ordini.cerca-articoli');
    });
    Route::middleware('ruolo:pianificazione,admin')->group(function () {
        Route::get('/ordini/nuovo', [OrdineController::class, 'create'])->name('ordini.create');
        Route::post('/ordini', [OrdineController::class, 'store'])->name('ordini.store');
    });
    Route::middleware('ruolo:pianificazione,admin,backoffice')->group(function () {
        Route::get('/ordini/{ordine}', [OrdineController::class, 'show'])->name('ordini.show');
    });
});

/*
| Amministrazione (§7): solo ruolo Admin. CRUD reparti, tipi fase, mappatura articoli, utenti.
*/
Route::middleware(['auth', 'ruolo:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminHomeController::class, 'index'])->name('index');

    Route::get('reparti', [RepartoController::class, 'index'])->name('reparti.index');
    Route::post('reparti', [RepartoController::class, 'store'])->name('reparti.store');
    Route::put('reparti/{reparto}', [RepartoController::class, 'update'])->name('reparti.update');
    Route::delete('reparti/{reparto}', [RepartoController::class, 'destroy'])->name('reparti.destroy');

    Route::get('tipi-fase', [TipoFaseController::class, 'index'])->name('tipi-fase.index');
    Route::post('tipi-fase', [TipoFaseController::class, 'store'])->name('tipi-fase.store');
    Route::put('tipi-fase/{tipoFase}', [TipoFaseController::class, 'update'])->name('tipi-fase.update');
    Route::delete('tipi-fase/{tipoFase}', [TipoFaseController::class, 'destroy'])->name('tipi-fase.destroy');

    Route::get('articoli-config', [ArticoloConfigController::class, 'index'])->name('articoli-config.index');
    Route::post('articoli-config', [ArticoloConfigController::class, 'store'])->name('articoli-config.store');
    Route::put('articoli-config/{config}', [ArticoloConfigController::class, 'update'])->name('articoli-config.update');
    Route::delete('articoli-config/{config}', [ArticoloConfigController::class, 'destroy'])->name('articoli-config.destroy');

    Route::get('utenti', [UtenteController::class, 'index'])->name('utenti.index');
    Route::post('utenti', [UtenteController::class, 'store'])->name('utenti.store');
    Route::put('utenti/{utente}', [UtenteController::class, 'update'])->name('utenti.update');
    Route::delete('utenti/{utente}', [UtenteController::class, 'destroy'])->name('utenti.destroy');
});

require __DIR__.'/auth.php';
