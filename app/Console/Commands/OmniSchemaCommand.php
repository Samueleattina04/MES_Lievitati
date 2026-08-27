<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * Introspezione del DB gestionale Omni (Microsoft Access via ODBC, sola lettura). Serve a scoprire il
 * nome della tabella dei lotti e le sue colonne (lotto fornitore, lotto Omni, articolo, data, giacenza)
 * prima di scrivere l'adapter di mappatura lotto fornitore -> lotto Omni (§6-bis). Va eseguito SUL
 * SERVER (dove esistono il driver ODBC Access e il DSN ACCESS_DSN nel .env).
 *
 * Esempi:
 *   php artisan omni:schema                       # elenca le tabelle
 *   php artisan omni:schema --table="Lotti"       # colonne + righe di esempio della tabella
 *   php artisan omni:schema --table="Lotti" --sample=10
 */
class OmniSchemaCommand extends Command
{
    protected $signature = 'omni:schema
        {--table= : Nome tabella da ispezionare (colonne + esempio). Se assente, elenca le tabelle}
        {--sample=5 : Righe di esempio (TOP N) quando si ispeziona una tabella}';

    protected $description = 'Ispeziona il DB Omni (Access via ODBC): elenca le tabelle o le colonne di una tabella.';

    public function handle(): int
    {
        $cfg = (array) config('mes.omni.connessione');
        $dsn = trim((string) ($cfg['dsn'] ?? ''));
        if ($dsn === '') {
            $this->error('ACCESS_DSN non configurato nel .env.');

            return self::FAILURE;
        }

        try {
            $pdo = new PDO('odbc:'.$dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''));
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Throwable $e) {
            $this->error('Connessione Omni fallita: '.$e->getMessage());
            $this->warn('Verifica: driver "Microsoft Access Driver (*.mdb, *.accdb)" a 64 bit, estensione pdo_odbc, path DSN raggiungibile dall\'app pool IIS.');

            return self::FAILURE;
        }

        $table = trim((string) ($this->option('table') ?? ''));
        if ($table === '') {
            return $this->elencaTabelle($pdo, $dsn, $cfg);
        }

        return $this->ispezionaTabella($pdo, $table, (int) $this->option('sample'));
    }

    /** @param array<string,mixed> $cfg */
    private function elencaTabelle(PDO $pdo, string $dsn, array $cfg): int
    {
        // 1) Via MSysObjects (tabelle utente).
        try {
            $rows = $pdo->query(
                "SELECT Name FROM MSysObjects WHERE Type IN (1,4,6) AND Left(Name,4)<>'MSys' AND Left(Name,1)<>'~' ORDER BY Name"
            )->fetchAll(PDO::FETCH_COLUMN);

            if ($rows) {
                $this->info('Tabelle Omni:');
                foreach ($rows as $t) {
                    $this->line(' - '.$t);
                }

                return self::SUCCESS;
            }
        } catch (Throwable $e) {
            $this->warn('MSysObjects non accessibile ('.$e->getMessage().').');
        }

        // 2) Fallback: catalogo ODBC (estensione odbc), se disponibile.
        if (function_exists('odbc_connect') && function_exists('odbc_tables')) {
            try {
                $conn = odbc_connect($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''));
                if ($conn) {
                    $res = odbc_tables($conn);
                    $this->info('Tabelle Omni (via ODBC):');
                    while ($r = odbc_fetch_array($res)) {
                        if (($r['TABLE_TYPE'] ?? '') === 'TABLE') {
                            $this->line(' - '.($r['TABLE_NAME'] ?? ''));
                        }
                    }

                    return self::SUCCESS;
                }
            } catch (Throwable $e) {
                $this->warn('odbc_tables fallito: '.$e->getMessage());
            }
        }

        $this->error('Impossibile elencare le tabelle automaticamente.');
        $this->line('Apri il file .accdb in Microsoft Access e leggi i nomi delle tabelle, poi rilancia con --table="NomeTabella".');

        return self::FAILURE;
    }

    private function ispezionaTabella(PDO $pdo, string $table, int $sample): int
    {
        if (! preg_match('/^[A-Za-z0-9_ ]+$/', $table)) {
            $this->error("Nome tabella non valido: {$table}");

            return self::FAILURE;
        }
        $sample = max(1, min($sample, 50));

        try {
            $rows = $pdo->query("SELECT TOP {$sample} * FROM [{$table}]")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->error('Errore leggendo la tabella: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('Tabella vuota o senza permessi. Colonne non ricavabili dai dati.');

            return self::SUCCESS;
        }

        $colonne = array_keys($rows[0]);
        $this->info("Colonne di [{$table}]:");
        foreach ($colonne as $c) {
            $this->line(' - '.$c);
        }
        $this->evidenziaCandidati($colonne);

        $this->newLine();
        $this->line("<comment>Prime righe di esempio:</comment>");
        foreach ($rows as $i => $row) {
            // I dati Access sono in Windows-1252: converto in UTF-8 per non far fallire il JSON.
            $row = array_map(static function ($v) {
                if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
                    return mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                }

                return $v;
            }, $row);
            $this->line('  #'.($i + 1).' '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return self::SUCCESS;
    }

    /** @param list<string> $colonne */
    private function evidenziaCandidati(array $colonne): void
    {
        $gruppi = [
            'Articolo' => '/(art|codart|prodotto|material)/i',
            'Lotto fornitore' => '/(fornit|forn|lottofor|lotto.*forn|forn.*lotto)/i',
            'Lotto Omni' => '/(lotto|lot)/i',
            'Data (FIFO)' => '/(data|dat|carico|creaz|ingr|scad)/i',
            'Giacenza (>0)' => '/(giac|esist|dispon|qta|quant|saldo|residu|rimanen)/i',
        ];

        $this->newLine();
        $this->line('<comment>Candidati per la mappatura:</comment>');
        foreach ($gruppi as $etichetta => $regex) {
            $match = array_values(array_filter($colonne, fn ($n) => preg_match($regex, (string) $n)));
            $this->line('  '.$etichetta.': '.($match !== [] ? implode(', ', $match) : '<fg=gray>—</>'));
        }
    }
}
