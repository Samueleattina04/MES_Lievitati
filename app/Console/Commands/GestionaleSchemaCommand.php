<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Introspezione dello schema del gestionale (ESOLVER, connessione sqlsrv_gestionale, sola lettura).
 * Serve a scoprire le colonne reali di tabelle come MagProgrLotto / MagProgrArticoli prima di
 * scrivere l'adapter giacenze/lotti (§3 GiacenzaMag06, §5). Va eseguito SUL SERVER (dove esistono
 * il driver sqlsrv e le credenziali GESTIONALE_DB_*).
 *
 * Esempi:
 *   php artisan gestionale:schema MagProgrLotto MagProgrArticoli
 *   php artisan gestionale:schema MagProgrLotto --sample=5
 */
class GestionaleSchemaCommand extends Command
{
    protected $signature = 'gestionale:schema
        {tabelle* : Uno o piu nomi di tabella da ispezionare}
        {--sample=0 : Mostra le prime N righe di esempio (TOP N) per capire i dati}';

    protected $description = "Elenca le colonne (e opzionalmente righe di esempio) di tabelle del gestionale ESOLVER.";

    public function handle(): int
    {
        $connessione = DB::connection('sqlsrv_gestionale');

        foreach ($this->argument('tabelle') as $tabella) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', (string) $tabella)) {
                $this->error("Nome tabella non valido: {$tabella}");

                continue;
            }

            $this->newLine();
            $this->line("================ <info>{$tabella}</info> ================");

            try {
                $colonne = $connessione->select(
                    'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_NAME = ?
                     ORDER BY ORDINAL_POSITION',
                    [$tabella],
                );
            } catch (Throwable $e) {
                $this->error('Errore interrogando lo schema: '.$e->getMessage());
                $this->warn('Verifica: estensioni sqlsrv/pdo_sqlsrv installate, GESTIONALE_DB_* valorizzate, permessi db_datareader.');

                continue;
            }

            if ($colonne === []) {
                $this->warn("Nessuna colonna trovata (tabella inesistente o senza permessi?).");

                continue;
            }

            $righe = array_map(fn ($c) => [
                $c->COLUMN_NAME,
                $c->DATA_TYPE.$this->dimensione($c),
                $c->IS_NULLABLE,
            ], $colonne);
            $this->table(['Colonna', 'Tipo', 'Null'], $righe);

            $this->evidenziaCandidati($colonne);

            $sample = (int) $this->option('sample');
            if ($sample > 0) {
                $this->stampaEsempio($connessione, (string) $tabella, $sample);
            }
        }

        $this->newLine();
        $this->line('Suggerimento: individua 3 colonne — magazzino (per filtrare "06"), giacenza/disponibile, data (FIFO).');

        return self::SUCCESS;
    }

    private function dimensione(object $c): string
    {
        if ($c->CHARACTER_MAXIMUM_LENGTH !== null) {
            return "({$c->CHARACTER_MAXIMUM_LENGTH})";
        }
        if ($c->NUMERIC_PRECISION !== null && in_array(strtolower((string) $c->DATA_TYPE), ['decimal', 'numeric'], true)) {
            return "({$c->NUMERIC_PRECISION},{$c->NUMERIC_SCALE})";
        }

        return '';
    }

    /**
     * @param array<int,object> $colonne
     */
    private function evidenziaCandidati(array $colonne): void
    {
        $nomi = array_map(fn ($c) => (string) $c->COLUMN_NAME, $colonne);

        $gruppi = [
            'Magazzino/deposito (filtro "06")' => '/(mag|dep|deposit|magazz)/i',
            'Giacenza/quantita disponibile' => '/(giac|esist|dispon|qta|quant|saldo|prog|carico|scaric)/i',
            'Data (FIFO: ingresso/scadenza)' => '/(data|dat|scad|ingr|carico|prod|creaz)/i',
            'Lotto' => '/(lotto|lot)/i',
        ];

        $this->line('<comment>Candidati per la mappatura:</comment>');
        foreach ($gruppi as $etichetta => $regex) {
            $match = array_values(array_filter($nomi, fn ($n) => preg_match($regex, $n)));
            $this->line('  '.$etichetta.': '.($match !== [] ? implode(', ', $match) : '<fg=gray>—</>'));
        }
    }

    private function stampaEsempio($connessione, string $tabella, int $n): void
    {
        try {
            // Il nome tabella e' gia' validato ^[A-Za-z0-9_]+$: sicuro da interpolare fra parentesi.
            $rows = $connessione->select("SELECT TOP ({$n}) * FROM [{$tabella}] WITH (NOLOCK)");
        } catch (Throwable $e) {
            $this->warn('Impossibile leggere righe di esempio: '.$e->getMessage());

            return;
        }

        $this->newLine();
        $this->line("<comment>Prime {$n} righe di esempio:</comment>");
        foreach ($rows as $i => $row) {
            $this->line('  #'.($i + 1).' '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
