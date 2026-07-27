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
 * FIFO: i campi del gestionale sono inutilizzabili (RifLottoNum sempre 0, RifLottoData sentinella
 * 1800-01-01). La data e' codificata nel CODICE LOTTO (vedi LottoFifoParser); quando
 * mes.stock.fifo_da_codice_lotto e' attivo, i lotti vengono riordinati per quella chiave. In
 * fallback resta l'ordinamento SQL sul campo 'campo_fifo'.
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

    public function giacenzaTotale(string $codiceArticolo): float
    {
        $tab = $this->ident($this->config['tabella_articoli']);
        $colCod = $this->ident($this->config['col_codice_articolo']);
        $colGiac = $this->ident($this->config['col_giacenza_articolo']);

        // Somma su TUTTI i magazzini: nessun filtro su CodMag.
        $row = $this->connection->selectOne(
            "SELECT SUM({$colGiac}) AS giacenza FROM {$tab} WITH (NOLOCK) WHERE {$colCod} = ?",
            [$codiceArticolo],
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

        $lotti = array_map(static fn ($r) => new StockLotto(
            lotto: (string) $r->lotto,
            quantita: (float) $r->qta,
            rifFifo: $r->rif ?? null,
        ), $rows);

        return $this->ordinaFifo($lotti);
    }

    public function lottiTuttiMagazzini(string $codiceArticolo): array
    {
        $tab = $this->ident($this->config['tabella_lotti']);
        $colCod = $this->ident($this->config['col_codice_articolo']);
        $colMag = $this->ident($this->config['col_magazzino']);
        $colLotto = $this->ident($this->config['col_lotto']);
        $colGiac = $this->ident($this->config['col_giacenza_lotto']);

        // Tutti i magazzini (nessun filtro su CodMag), solo lotti con giacenza.
        $rows = $this->connection->select(
            "SELECT {$colMag} AS magazzino, {$colLotto} AS lotto, {$colGiac} AS qta
             FROM {$tab} WITH (NOLOCK)
             WHERE {$colCod} = ? AND {$colGiac} > 0",
            [$codiceArticolo],
        );

        // Aggrega per (magazzino, lotto) sommando le giacenze.
        $out = [];
        $idx = [];
        foreach ($rows as $r) {
            $mag = (string) $r->magazzino;
            $lot = (string) $r->lotto;
            $k = $mag.'|'.$lot;
            if (isset($idx[$k])) {
                $out[$idx[$k]]['quantita'] += (float) $r->qta;
            } else {
                $idx[$k] = count($out);
                $out[] = ['magazzino' => $mag, 'lotto' => $lot, 'quantita' => (float) $r->qta];
            }
        }

        // Ordina per magazzino e, all'interno, in ottica FIFO dal codice lotto (se attivo).
        $fifoDaCodice = (bool) ($this->config['fifo_da_codice_lotto'] ?? false);
        usort($out, static function (array $a, array $b) use ($fifoDaCodice): int {
            $m = strcmp($a['magazzino'], $b['magazzino']);
            if ($m !== 0) {
                return $m;
            }
            if ($fifoDaCodice) {
                return LottoFifoParser::confronta(
                    LottoFifoParser::chiave($a['lotto']),
                    LottoFifoParser::chiave($b['lotto']),
                );
            }

            return strcmp($a['lotto'], $b['lotto']);
        });

        return $out;
    }

    /**
     * Ordina i lotti del mag. 06 in ottica FIFO dal codice lotto (se attivo), altrimenti lascia
     * l'ordine SQL sul campo FIFO. I lotti fuori formato restano in coda (usort stabile su PHP 8).
     *
     * @param  list<StockLotto>  $lotti
     * @return list<StockLotto>
     */
    private function ordinaFifo(array $lotti): array
    {

        // FIFO dal codice lotto (§5.2): l'ordine cronologico reale e' codificato nel codice lotto,
        // non nei campi del gestionale. Riordina dal piu' vecchio al piu' recente; i lotti fuori
        // formato (es. fornitori esterni) restano in coda nell'ordine SQL di partenza (usort stabile).
        if ((bool) ($this->config['fifo_da_codice_lotto'] ?? false)) {
            usort($lotti, static fn (StockLotto $a, StockLotto $b) => LottoFifoParser::confronta(
                LottoFifoParser::chiave($a->lotto),
                LottoFifoParser::chiave($b->lotto),
            ));
        }

        return $lotti;
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
