<?php

declare(strict_types=1);

namespace App\Stock\Contracts;

/**
 * Adapter disaccoppiato verso la sorgente delle giacenze del magazzino 06 (§3, §5).
 * Produzione: SqlServerStockAdapter (ESOLVER). Sviluppo/test: FixtureStockAdapter.
 * Stesso pattern di BomSourceAdapterInterface: la logica di dominio dipende solo da qui.
 */
interface StockSourceAdapterInterface
{
    /**
     * Giacenza disponibile sul mag. 06 per l'articolo (UM primaria). Usata per il blocco
     * degli articoli NON a lotto (§5.1). Se l'articolo non e' presente sul mag. 06 -> 0.
     */
    public function giacenzaArticolo(string $codiceArticolo): float;

    /**
     * Giacenza TOTALE nota dell'articolo su TUTTI i magazzini (somma di QtaGiacUmMag su ogni CodMag),
     * NON filtrata sul 06. Usata per l'avviso soft (non bloccante) sui lotti inseriti manualmente.
     */
    public function giacenzaTotale(string $codiceArticolo): float;

    /**
     * Lotti disponibili sul mag. 06 per l'articolo (giacenza > 0), ordinati in ottica FIFO (§5.2).
     *
     * @return list<\App\Stock\StockLotto>
     */
    public function lottiDisponibiliFifo(string $codiceArticolo): array;
}
