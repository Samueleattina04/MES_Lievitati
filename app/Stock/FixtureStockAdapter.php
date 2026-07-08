<?php

declare(strict_types=1);

namespace App\Stock;

use App\Stock\Contracts\StockSourceAdapterInterface;

/**
 * Adapter giacenze per sviluppo/test. Legge dati in memoria (o da un JSON `giacenze.json`):
 *   { "CODART": { "giacenza": 100, "lotti": [ {"lotto":"L1","quantita":40,"rif_fifo":1}, ... ] } }
 *
 * Convenzione: un articolo NON presente nei dati ha giacenza "illimitata" di default, cosi' il
 * fixture non blocca mai per giacenza a meno che un test non definisca esplicitamente scorte limitate
 * (passando $giacenzaDefault = 0.0 e i soli articoli rilevanti). Il vero SqlServerStockAdapter,
 * al contrario, restituisce 0 per gli articoli assenti dal mag. 06.
 */
final class FixtureStockAdapter implements StockSourceAdapterInterface
{
    private const ILLIMITATA = 1_000_000_000.0;

    /**
     * @param array<string, array{giacenza?: float|int, lotti?: list<array<string,mixed>>}> $dati
     */
    public function __construct(
        private readonly array $dati = [],
        private readonly ?float $giacenzaDefault = null,
    ) {}

    public static function daFile(string $path, ?float $giacenzaDefault = null): self
    {
        $file = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'giacenze.json';
        $dati = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return new self(is_array($dati) ? $dati : [], $giacenzaDefault);
    }

    public function giacenzaArticolo(string $codiceArticolo): float
    {
        if (isset($this->dati[$codiceArticolo]['giacenza'])) {
            return (float) $this->dati[$codiceArticolo]['giacenza'];
        }

        return $this->giacenzaDefault ?? self::ILLIMITATA;
    }

    public function giacenzaTotale(string $codiceArticolo): float
    {
        // Se il fixture non specifica il totale su tutti i magazzini, ripiega sulla giacenza nota.
        if (isset($this->dati[$codiceArticolo]['giacenza_totale'])) {
            return (float) $this->dati[$codiceArticolo]['giacenza_totale'];
        }

        return $this->giacenzaArticolo($codiceArticolo);
    }

    public function lottiDisponibiliFifo(string $codiceArticolo): array
    {
        $lotti = $this->dati[$codiceArticolo]['lotti'] ?? [];

        $out = array_map(static fn (array $l) => new StockLotto(
            lotto: (string) $l['lotto'],
            quantita: (float) $l['quantita'],
            rifFifo: $l['rif_fifo'] ?? null,
        ), $lotti);

        // Ordinamento FIFO sul rif (proxy provvisorio: RifLottoNum crescente).
        usort($out, static fn (StockLotto $a, StockLotto $b) => ($a->rifFifo ?? 0) <=> ($b->rifFifo ?? 0));

        return $out;
    }
}
