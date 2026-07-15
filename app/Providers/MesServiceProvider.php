<?php

declare(strict_types=1);

namespace App\Providers;

use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Bom\FixtureBomAdapter;
use App\Bom\SqlServerBomAdapter;
use App\Export\EsportazioneService;
use App\Export\Templates\ConsumiLottiCsvTemplate;
use App\Export\Templates\TracciatoJsonTemplate;
use App\Export\Templates\VersamentiCsvTemplate;
use App\Ordini\OrderExplosionPlanner;
use App\Produzione\ChiusuraMassivaService;
use App\Produzione\FaseWorkflowService;
use App\Produzione\GenealogiaService;
use App\Produzione\SplitService;
use App\Models\User;
use App\Stock\Contracts\LottoSemilavoratoSourceInterface;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FixtureStockAdapter;
use App\Stock\LottiProdottoSemilavoratoSource;
use App\Stock\SqlServerStockAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class MesServiceProvider extends ServiceProvider
{
    /**
     * Binding dell'adapter distinte in base a config('mes.bom_adapter').
     * Il resto del dominio dipende solo dall'interfaccia (§4.1).
     */
    public function register(): void
    {
        $this->app->singleton(BomSourceAdapterInterface::class, function (Application $app): BomSourceAdapterInterface {
            $driver = (string) config('mes.bom_adapter', 'sqlsrv');

            return match ($driver) {
                'sqlsrv' => new SqlServerBomAdapter(DB::connection('sqlsrv_gestionale')),
                'fixture' => new FixtureBomAdapter((string) config('mes.fixture_path')),
                default => throw new InvalidArgumentException(
                    "Adapter distinte non valido: '{$driver}'. Valori ammessi: 'sqlsrv', 'fixture'."
                ),
            };
        });

        $this->app->bind(
            OrderExplosionPlanner::class,
            fn () => new OrderExplosionPlanner((int) config('mes.decimali_quantita', 6)),
        );

        $this->app->bind(
            SplitService::class,
            fn () => new SplitService((float) config('mes.tolleranza_split', 0.01)),
        );

        // Adapter giacenze mag. 06 (§5): reale ESOLVER o fixture (dev/test).
        $this->app->singleton(StockSourceAdapterInterface::class, function (): StockSourceAdapterInterface {
            $config = (array) config('mes.stock');
            $driver = (string) ($config['adapter'] ?? 'sqlsrv');

            return match ($driver) {
                'sqlsrv' => new SqlServerStockAdapter(DB::connection('sqlsrv_gestionale'), $config),
                'fixture' => FixtureStockAdapter::daFile((string) $config['fixture_path']),
                default => throw new InvalidArgumentException(
                    "Adapter giacenze non valido: '{$driver}'. Valori ammessi: 'sqlsrv', 'fixture'."
                ),
            };
        });

        // Sorgente per riconoscere i lotti di semilavorato gia' esistenti (§5.3, change #3).
        $this->app->singleton(LottoSemilavoratoSourceInterface::class, function (): LottoSemilavoratoSourceInterface {
            $sorgente = (string) config('mes.semilavorato.sorgente_lotti', 'lotti_prodotto');

            return match ($sorgente) {
                'lotti_prodotto' => new LottiProdottoSemilavoratoSource(),
                default => throw new InvalidArgumentException(
                    "Sorgente lotti semilavorato non valida: '{$sorgente}'. Valori ammessi: 'lotti_prodotto'."
                ),
            };
        });

        $this->app->bind(
            FaseWorkflowService::class,
            fn (Application $app) => new FaseWorkflowService(
                (float) config('mes.tolleranza_multilotto', 0.01),
                $app->make(StockSourceAdapterInterface::class),
                (bool) config('mes.stock.verifica_giacenza', true),
                $app->make(LottoSemilavoratoSourceInterface::class),
            ),
        );

        // Chiusura massiva backoffice (§8, change #4): orchestrazione bottom-up delle fasi di un ordine.
        $this->app->bind(
            ChiusuraMassivaService::class,
            fn (Application $app) => new ChiusuraMassivaService(
                $app->make(FaseWorkflowService::class),
                $app->make(SplitService::class),
            ),
        );

        // Motore di export a template (§10): registro dei tracciati disponibili.
        $this->app->bind(
            EsportazioneService::class,
            fn (Application $app) => new EsportazioneService([
                new ConsumiLottiCsvTemplate(),
                new VersamentiCsvTemplate(),
                new TracciatoJsonTemplate($app->make(GenealogiaService::class)),
            ]),
        );
    }

    public function boot(): void
    {
        // Gate derivati dalla matrice permessi del ruolo (unica fonte: App\Enums\RuoloUtente).
        // Usati come middleware 'can:...' sulle rotte e via $page.props.auth.can nella UI (§7).
        Gate::define('configurare', fn (User $u) => (bool) $u->ruolo?->puoConfigurare());
        Gate::define('gestire-ordini', fn (User $u) => (bool) $u->ruolo?->puoGestireOrdini());
        Gate::define('esportare', fn (User $u) => (bool) $u->ruolo?->puoEsportare());
        Gate::define('vedere-dashboard', fn (User $u) => (bool) $u->ruolo?->vedeDashboard());
        Gate::define('vedere-genealogia', fn (User $u) => (bool) $u->ruolo?->vedeGenealogia());
        // Avanzamento produzione (operatore + backoffice): area /operatore, /api/sync, chiusura massiva.
        Gate::define('avanzare-produzione', fn (User $u) => (bool) $u->ruolo?->puoAvanzareProduzione());
    }
}
