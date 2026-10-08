<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;
use PDO;

/**
 * Anagrafica articoli da Omni (Microsoft Access via ODBC, SOLA LETTURA), § Check. Per ogni codice legge
 * descrizione e id d'origine; riporta anche il nome della tabella (serve nel foglio "Omni non in Esolver"
 * per indicare provenienza + id). La connessione e le colonne sono parametrizzate (config/mes.php:
 * mes.check.omni.tabelle[]). Dati Access in Windows-1252 -> convertiti UTF-8.
 *
 * Legge UNA tabella; l'anagrafica Omni completa e' l'unione di piu' tabelle (T_INGREDIENTI + T_PRODOTTI),
 * fatta da {@see CompositeArticoliSource} istanziando questo adapter una volta per tabella.
 */
final class AccessArticoliOmniAdapter implements ArticoliSourceInterface
{
    private string $tabella;

    private string $colCodice;

    private string $colDescrizione;

    private string $colId;

    /**
     * @param  array<string,mixed>  $connessione  ['dsn','username','password']
     * @param  array<string,mixed>  $config       ['tabella','col_codice','col_descrizione','col_id']
     */
    public function __construct(
        private readonly array $connessione,
        array $config = [],
    ) {
        $this->tabella = (string) ($config['tabella'] ?? 'T_INGREDIENTI');
        $this->colCodice = (string) ($config['col_codice'] ?? 'CodGestionale');
        $this->colDescrizione = (string) ($config['col_descrizione'] ?? '');
        $this->colId = (string) ($config['col_id'] ?? '');
    }

    public function articoli(): array
    {
        $cc = $this->colCodice;
        $cd = trim($this->colDescrizione);
        $ci = trim($this->colId);

        // Una riga per codice (GROUP BY): id e descrizione presi in aggregato (MIN/MAX) per determinismo.
        $select = "[{$cc}] AS Codice";
        if ($ci !== '') {
            $select .= ", MIN([{$ci}]) AS Id";
        }
        if ($cd !== '') {
            $select .= ", MAX([{$cd}]) AS Descrizione";
        }
        $sql = "SELECT {$select} FROM [{$this->tabella}] WHERE [{$cc}] IS NOT NULL GROUP BY [{$cc}]";

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
            $out[$codice] = [
                'descrizione' => $cd !== '' ? trim($this->utf8((string) ($r['Descrizione'] ?? ''))) : '',
                'tabella' => $this->tabella,
                'id' => $ci !== '' ? trim($this->utf8((string) ($r['Id'] ?? ''))) : '',
            ];
        }

        return $out;
    }

    /** I dati Access sono in Windows-1252: garantisce UTF-8 in uscita. */
    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
