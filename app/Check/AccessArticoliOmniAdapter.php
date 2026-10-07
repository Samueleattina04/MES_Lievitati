<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;
use PDO;

/**
 * Anagrafica articoli da Omni (Microsoft Access via ODBC, SOLA LETTURA), § Check. Legge i codici ESOLVER
 * noti a Omni (campo `CodArtEsolver`) in modo DISTINCT. La connessione e le colonne sono parametrizzate
 * (config/mes.php: mes.check.omni + mes.omni.connessione). Dati Access in Windows-1252 -> convertiti UTF-8.
 *
 * Nota: di default (config) legge l'anagrafica completa `T_INGREDIENTI` col codice `CodGestionale`. In
 * alternativa si puo' usare `CodEsolver` (collegamento esplicito a ESOLVER, ma spesso vuoto) via
 * MES_CHECK_OMNI_COL, oppure un'altra tabella via MES_CHECK_OMNI_TABELLA.
 */
final class AccessArticoliOmniAdapter implements ArticoliSourceInterface
{
    private string $tabella;

    private string $colCodice;

    private string $colDescrizione;

    /**
     * @param  array<string,mixed>  $connessione  ['dsn','username','password']
     * @param  array<string,mixed>  $config       ['tabella','col_codice','col_descrizione']
     */
    public function __construct(
        private readonly array $connessione,
        array $config = [],
    ) {
        $this->tabella = (string) ($config['tabella'] ?? 'T_Linkfattlotti');
        $this->colCodice = (string) ($config['col_codice'] ?? 'CodArtEsolver');
        $this->colDescrizione = (string) ($config['col_descrizione'] ?? '');
    }

    public function articoli(): array
    {
        $cc = $this->colCodice;
        $cd = trim($this->colDescrizione);

        // DISTINCT sul codice; la descrizione (se c'e') si prende con MAX per avere una riga per codice.
        $sql = $cd !== ''
            ? "SELECT [{$cc}] AS Codice, MAX([{$cd}]) AS Descrizione FROM [{$this->tabella}] "
                ."WHERE [{$cc}] IS NOT NULL GROUP BY [{$cc}]"
            : "SELECT DISTINCT [{$cc}] AS Codice FROM [{$this->tabella}] WHERE [{$cc}] IS NOT NULL";

        $pdo = new PDO(
            'odbc:'.trim((string) ($this->connessione['dsn'] ?? '')),
            (string) ($this->connessione['username'] ?? ''),
            (string) ($this->connessione['password'] ?? ''),
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $codice = trim($this->utf8((string) ($r['Codice'] ?? '')));
            if ($codice === '') {
                continue;
            }
            $out[$codice] = $cd !== '' ? trim($this->utf8((string) ($r['Descrizione'] ?? ''))) : '';
        }

        return $out;
    }

    /** I dati Access sono in Windows-1252: garantisce UTF-8 in uscita. */
    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
