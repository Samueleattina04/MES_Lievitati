<?php

declare(strict_types=1);

namespace App\Stock;

use App\Stock\Contracts\StockSourceAdapterInterface;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Adapter di PRODUZIONE verso ESOLVER (SQL Server, sola lettura, WITH (NOLOCK)).
 * Giacenza articolo da MagProgrArticoli, lotti disponibili da MagProgrLotto, filtrati su
 * magazzino 06. Nomi di tabelle/colonne e campo FIFO arrivano da config (config/mes.php > stock),
 * quindi la query si adatta senza modifiche al codice.
 *
 * // PROVVISORIO: l'ordinamento FIFO usa il campo config 'campo_fifo' (attualmente RifLottoNum),
 * // in attesa di conferma dal gestionale sul campo cronologico corretto (RifLottoData e' sentinella).
 */
final class SqlServerStockAdapter implements StockSourceAdapterInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        /** @var array<string,mixed> */
        private readonly array $config,
    ) {}

    public function giacenzaArticolo(string $codiceArticolo): float
    {
        $tab = $this->ident($this->config['tabella_articoli']);
        $colCod = $this->ident($this->config['col_codice_articolo']);
        $colMag = $this->ident($this->config['col_magazzino']);
        $colGiac = $this->ident($this->config['col_giacenza_articolo']);

        $row = $this->connection->selectOne(
            "SELECT SUM({$colGiac}) AS giacenza FROM {$tab} WITH (NOLOCK) WHERE {$colCod} = ? AND {$colMag} = ?",
            [$codiceArticolo, (string) $this->config['magazzino']],
        );

        return (float) ($row->giacenza ?? 0);
    }

    public function lottiDisponibiliFifo(string $codiceArticolo): array
    {
        $tab = $this->ident($this->config['tabella_lotti']);
        $colCod = $this->ident($this->config['col_codice_articolo']);
        $colMag = $this->ident($this->config['col_magazzino']);
        $colLotto = $this->ident($this->config['col_lotto']);
        $colGiac = $this->ident($this->config['col_giacenza_lotto']);
        $colFifo = $this->ident($this->config['campo_fifo']);
        $dir = strtolower((string) $this->config['fifo_direzione']) === 'desc' ? 'DESC' : 'ASC';

        $rows = $this->connection->select(
            "SELECT {$colLotto} AS lotto, {$colGiac} AS qta, {$colFifo} AS rif
             FROM {$tab} WITH (NOLOCK)
             WHERE {$colCod} = ? AND {$colMag} = ? AND {$colGiac} > 0
             ORDER BY {$colFifo} {$dir}",
            [$codiceArticolo, (string) $this->config['magazzino']],
        );

        return array_map(static fn ($r) => new StockLotto(
            lotto: (string) $r->lotto,
            quantita: (float) $r->qta,
            rifFifo: $r->rif ?? null,
        ), $rows);
    }

    /**
     * Valida e racchiude un identificatore SQL (nome tabella/colonna) da config, contro injection.
     */
    private function ident(mixed $nome): string
    {
        $nome = (string) $nome;
        if (! preg_match('/^[A-Za-z0-9_]+$/', $nome)) {
            throw new InvalidArgumentException("Identificatore SQL non valido in config mes.stock: '{$nome}'.");
        }

        return "[{$nome}]";
    }
}
