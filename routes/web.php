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
use App\Http\Controllers\Produzione\ChiusuraController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();
    if ($user === null) {
        return redirect()->route('login');
    }

    // Home in base al ruolo (§7): operatore -> coda, admin -> configurazione, altri -> dashboard.
    return redirect()->route($user->ruolo->rottaHome());
});

/*
| Area operatore (§7, §8): login rapido via PIN su tablet condiviso e coda di lavoro.
| L'AVANZAMENTO (coda/esecuzione/split/sync) e' aperto a chi puo' "avanzare produzione"
| (operatore + backoffice, change #1). Il filtro per reparto e' applicato nei controller:
| l'operatore vede solo i propri reparti, il backoffice tutti.
*/
Route::prefix('operatore')->name('operatore.')->group(function () {
    Route::get('login', [OperatoreAuthController::class, 'showLogin'])->name('login');
    // Il rate limiting fine (per IP, con messaggi) e' gestito nel controller; qui un guard generico.
    Route::post('pin-login', [OperatoreAuthController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('pin-login');

    Route::middleware(['auth', 'can:avanzare-produzione'])->group(function () {
        Route::post('logout', [OperatoreAuthController::class, 'logout'])->name('logout');
        Route::get('/', [EsecuzioneController::class, 'coda'])->name('coda');
        Route::get('step/{step}', [EsecuzioneController::class, 'show'])->name('fase');
        Route::post('step/{step}/avvia', [EsecuzioneController::class, 'avvia'])->name('step.avvia');
        Route::post('step/{step}/materiale/{materiale}/conferma', [EsecuzioneController::class, 'confermaMateriale'])->name('materiale.conferma');
        Route::post('step/{step}/chiudi', [EsecuzioneController::class, 'chiudi'])->name('step.chiudi');
        // Prelievo da stock: chiude la fase con un lotto di semilavorato esistente (§5.3, change #3).
        Route::post('step/{step}/completa-da-stock', [EsecuzioneController::class, 'completaDaStock'])->name('step.completa-da-stock');

        // Ripartizione (split) di un nodo condiviso (§5-bis).
        Route::get('split/{fase}', [SplitController::class, 'show'])->name('split');
        Route::post('split/{fase}', [SplitController::class, 'store'])->name('split.store');
    });
});

// Sincronizzazione coda offline (§8): endpoint /api/sync idempotente, per online e replay.
Route::middleware(['auth', 'can:avanzare-produzione'])
    ->post('/api/sync', [SyncController::class, 'store'])
    ->name('operatore.sync');

/*
| Avanzamento produzione da backoffice (§8, change #4): elenco ordini da chiudere e chiusura
| massiva dalla distinta esplosa. Stesso permesso dell'area operatore ('avanzare-produzione');
| in pratica usata dal backoffice (l'operatore lavora dai tablet in /operatore). La chiusura
| guidata fase-per-fase riusa /operatore/coda (senza vincolo di reparto per il backoffice).
*/
Route::middleware(['auth', 'can:avanzare-produzione'])->prefix('produzione')->name('produzione.')->group(function () {
    Route::get('/', [ChiusuraController::class, 'index'])->name('index');
    Route::get('/{ordine}/chiusura-massiva', [ChiusuraController::class, 'chiusuraMassiva'])->name('chiusura-massiva');
    Route::post('/{ordine}/chiusura-massiva', [ChiusuraController::class, 'chiudi'])->name('chiudi-massivo');
});

/*
| Area backoffice/pianificazione/admin (email + password).
*/
Route::middleware('auth')->group(function () {
    // Dashboard (§9): admin, backoffice, pianificazione.
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('can:vedere-dashboard')
        ->name('dashboard');

    // Genealogia lotti (§6).
    Route::get('/genealogia', [GenealogiaController::class, 'index'])
        ->middleware('can:vedere-genealogia')
        ->name('genealogia.index');

    // Export tracciati (§10): SOLO chi ha il permesso 'esportare' (backoffice + admin, NON pianificazione).
    Route::post('/export/{ordine}', [EsportazioneController::class, 'esporta'])
        ->middleware('can:esportare')
        ->name('export.esporta');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Ordini di produzione (§9): SOLO chi puo' gestire ordini (pianificazione + admin, NON backoffice).
    // Rotte statiche prima di /ordini/{ordine} per non farle catturare dal binding.
    Route::middleware('can:gestire-ordini')->group(function () {
        Route::get('/ordini', [OrdineController::class, 'index'])->name('ordini.index');
        Route::get('/ordini/ricerca-articoli', [OrdineController::class, 'cercaArticoli'])->name('ordini.cerca-articoli');
        Route::get('/ordini/nuovo', [OrdineController::class, 'create'])->name('ordini.create');
        Route::post('/ordini', [OrdineController::class, 'store'])->name('ordini.store');
        Route::get('/ordini/{ordine}', [OrdineController::class, 'show'])->name('ordini.show');
        // Cancellazione ordine (solo se "aperto", nessuna fase avviata — vedi controller).
        Route::delete('/ordini/{ordine}', [OrdineController::class, 'destroy'])->name('ordini.destroy');
    });
});

/*
| Amministrazione (§7): permesso 'configurare' (solo Admin). CRUD reparti, tipi fase, articoli, utenti.
*/
Route::middleware(['auth', 'can:configurare'])->prefix('admin')->name('admin.')->group(function () {
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
