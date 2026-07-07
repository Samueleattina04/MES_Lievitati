<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bom\BomExplosion;
use App\Bom\BomRow;
use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Bom\FixtureBomAdapter;
use App\Bom\SqlServerBomAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comando di test dell'adapter distinte (§14, Fase 1): stampa l'albero esploso di un articolo,
 * senza passare dalla UI. Consente di validare rapidamente SqlServerBomAdapter/FixtureBomAdapter.
 *
 * Esempi:
 *   php artisan bom:explode ASSPAN01
 *   php artisan bom:explode ASSPAN01 --qta=10
 *   php artisan bom:explode PAN0104 --adapter=fixture
 */
class BomExplodeCommand extends Command
{
    protected $signature = 'bom:explode
        {codice : Codice articolo radice da esplodere}
        {--qta=1 : Quantita di ordine per cui calcolare le quantita pianificate}
        {--adapter= : Forza l adapter (sqlsrv|fixture); default: config mes.bom_adapter}';

    protected $description = "Esplode la distinta base di un articolo e ne stampa l'albero (test adapter).";

    public function handle(): int
    {
        $codice = (string) $this->argument('codice');
        $qta = (float) $this->option('qta');

        try {
            $adapter = $this->risolviAdapter();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Adapter: <info>'.$adapter::class.'</info>');

        if (! $adapter->esisteArticolo($codice)) {
            $this->error("Articolo '{$codice}' non trovato nella sorgente distinte.");

            return self::FAILURE;
        }

        try {
            $esplosione = $adapter->explode($codice);
        } catch (Throwable $e) {
            $this->error('Esplosione fallita: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($esplosione->vuota()) {
            $this->warn("La distinta di '{$codice}' e' vuota.");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Distinta esplosa di <info>{$codice}</info> (quantita ordine: <info>{$qta}</info>)");
        $this->newLine();

        $this->stampaAlbero($esplosione, $codice, null, 0, $qta);

        $this->stampaRiepilogo($esplosione, $qta);

        return self::SUCCESS;
    }

    private function risolviAdapter(): BomSourceAdapterInterface
    {
        $override = $this->option('adapter');

        return match ($override) {
            null, '' => app(BomSourceAdapterInterface::class),
            'fixture' => new FixtureBomAdapter((string) config('mes.fixture_path')),
            'sqlsrv' => new SqlServerBomAdapter(DB::connection('sqlsrv_gestionale')),
            default => throw new \InvalidArgumentException("Adapter non valido: '{$override}'."),
        };
    }

    private function stampaAlbero(BomExplosion $e, string $articolo, ?string $padre, int $profondita, float $qta): void
    {
        $indent = str_repeat('  ', $profondita);

        // Riga corrente (radice se padre === null).
        $riga = $padre === null
            ? $e->rigaRadice()
            : $e->righe()->first(fn (BomRow $r) => $r->articolo === $articolo && $r->articoloPadre === $padre);

        if ($riga !== null) {
            $qtaPianificata = rtrim(rtrim(number_format($riga->qtaPerUnita * $qta, 6, '.', ''), '0'), '.');
            $tag = $riga->isProdotto ? '<comment>[FASE]</comment>' : '<fg=gray>[mat]</>';
            $condiviso = ($riga->isProdotto && $e->eCondiviso($articolo)) ? ' <fg=red>[CONDIVISO]</>' : '';
            $desc = $riga->descrizione ? " - {$riga->descrizione}" : '';
            $this->line(sprintf(
                '%s%s <info>%s</info> x %s %s%s%s',
                $indent,
                $tag,
                $articolo,
                $qtaPianificata,
                $riga->udm ?? '',
                $desc,
                $condiviso,
            ));
        }

        // Figli diretti (solo se e' un nodo prodotto). Per i nodi prodotti l'albero puo'
        // ripetere il sottoalbero condiviso: e' voluto (rispecchia l'esplosione reale).
        foreach ($e->figliDiretti($articolo) as $figlio) {
            if ($figlio->articoloPadre === $articolo && $profondita < 50) {
                $this->stampaAlberoFiglio($e, $figlio, $profondita + 1, $qta);
            }
        }
    }

    private function stampaAlberoFiglio(BomExplosion $e, BomRow $figlio, int $profondita, float $qta): void
    {
        $indent = str_repeat('  ', $profondita);
        $qtaPianificata = rtrim(rtrim(number_format($figlio->qtaPerUnita * $qta, 6, '.', ''), '0'), '.');
        $tag = $figlio->isProdotto ? '<comment>[FASE]</comment>' : '<fg=gray>[mat]</>';
        $condiviso = ($figlio->isProdotto && $e->eCondiviso($figlio->articolo)) ? ' <fg=red>[CONDIVISO]</>' : '';
        $desc = $figlio->descrizione ? " - {$figlio->descrizione}" : '';
        $this->line(sprintf(
            '%s%s <info>%s</info> x %s %s%s%s',
            $indent,
            $tag,
            $figlio->articolo,
            $qtaPianificata,
            $figlio->udm ?? '',
            $desc,
            $condiviso,
        ));

        if ($figlio->isProdotto && $profondita < 50) {
            foreach ($e->figliDiretti($figlio->articolo) as $nipote) {
                if ($nipote->articoloPadre === $figlio->articolo) {
                    $this->stampaAlberoFiglio($e, $nipote, $profondita + 1, $qta);
                }
            }
        }
    }

    private function stampaRiepilogo(BomExplosion $e, float $qta): void
    {
        $nodi = $e->codiciNodiProdotti();

        $this->newLine();
        $this->line('<info>Riepilogo nodi prodotti (= fasi generate):</info>');

        $righe = [];
        foreach ($nodi as $codice) {
            $qtaTot = $e->occorrenze($codice)->sum(fn (BomRow $r) => $r->qtaPerUnita) * $qta;
            $righe[] = [
                $codice,
                rtrim(rtrim(number_format($qtaTot, 6, '.', ''), '0'), '.'),
                count($e->figliDiretti($codice)->unique('articolo')),
                $e->eCondiviso($codice) ? 'SI' : '',
            ];
        }

        $this->table(['Nodo prodotto (fase)', 'Qta pianificata', 'N. materiali', 'Condiviso'], $righe);
        $this->line(sprintf(
            'Totale fasi: <info>%d</info>  |  di cui condivise (split): <info>%d</info>',
            count($nodi),
            count(array_filter($nodi, fn ($c) => $e->eCondiviso($c))),
        ));
    }
}
