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
use App\Produzione\FaseWorkflowService;
use App\Produzione\GenealogiaService;
use App\Produzione\SplitService;
use App\Stock\Contracts\StockSourceAdapterInterface;
use App\Stock\FixtureStockAdapter;
use App\Stock\SqlServerStockAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
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

        $this->app->bind(
            FaseWorkflowService::class,
            fn (Application $app) => new FaseWorkflowService(
                (float) config('mes.tolleranza_multilotto', 0.01),
                $app->make(StockSourceAdapterInterface::class),
                (bool) config('mes.stock.verifica_giacenza', true),
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
        //
    }
}
