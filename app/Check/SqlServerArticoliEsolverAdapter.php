<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Anagrafica articoli da ESOLVER (SQL Server), SOLA LETTURA (§ Check). Legge i codici (e la descrizione,
 * se configurata) da `ArtAnagrafica`. WITH (NOLOCK), nessuna scrittura. Colonne parametrizzate in
 * config/mes.php (mes.check.esolver) per adattarle senza toccare il codice.
 */
final class SqlServerArticoliEsolverAdapter implements ArticoliSourceInterface
{
    private string $tabella;

    private string $colCodice;

    private string $colDescrizione;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly ConnectionInterface $connection,
        array $config = [],
    ) {
        $this->tabella = (string) ($config['tabella'] ?? 'ArtAnagrafica');
        $this->colCodice = (string) ($config['col_codice'] ?? 'CodArt');
        $this->colDescrizione = (string) ($config['col_descrizione'] ?? 'Descrizione');
    }

    public function articoli(): array
    {
        $cod = $this->colCodice;
        $descr = trim($this->colDescrizione);

        $select = $descr !== ''
            ? "{$cod} AS Codice, {$descr} AS Descrizione"
            : "{$cod} AS Codice, '' AS Descrizione";

        $sql = "SELECT {$select} FROM {$this->tabella} WITH (NOLOCK)";

        $out = [];
        foreach ($this->connection->select($sql) as $r) {
            $r = (array) $r;
            $codice = trim((string) ($r['Codice'] ?? ''));
            if ($codice === '') {
                continue;
            }
            $out[$codice] = trim((string) ($r['Descrizione'] ?? ''));
        }

        return $out;
    }
}
