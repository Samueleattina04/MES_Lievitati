<?php

declare(strict_types=1);

namespace App\Bom\Contracts;

use App\Bom\BomExplosion;

/**
 * Adapter disaccoppiato verso la sorgente delle distinte base (§4.1).
 * Unica implementazione di produzione: SqlServerBomAdapter (gestionale Passepartout/Mexal).
 * In sviluppo/test: FixtureBomAdapter. Il resto del dominio dipende solo da questa interfaccia,
 * cosi' la sorgente puo' essere sostituita (altro gestionale, file, API) senza toccare la logica.
 */
interface BomSourceAdapterInterface
{
    /**
     * Esplode ricorsivamente la distinta del codice indicato, restituendo l'albero piatto
     * con le quantita' gia' normalizzate per unita' di prodotto finito (§4.3).
     */
    public function explode(string $codiceArticoloRadice): BomExplosion;

    /**
     * Verifica se il codice esiste come articolo con distinta (produbile) nella sorgente.
     */
    public function esisteArticolo(string $codice): bool;

    /**
     * Ricerca articoli producibili (con distinta) per la UI di creazione ordine.
     *
     * @return list<array{codice:string,descrizione:?string}>
     */
    public function cercaArticoli(string $query, int $limit = 25): array;

    /**
     * Per i codici indicati, dice se l'articolo e' "gestito a lotti" secondo l'anagrafica del
     * gestionale. Usato per popolare flag_lotto in automatico alla creazione ordine (§5.2), cosi'
     * da non doverlo configurare a mano. L'override manuale (flag_lotto_override) resta prioritario.
     *
     * @param  list<string>  $codici
     * @return array<string,bool>  mappa codice => gestito a lotti (i codici non trovati sono assenti)
     */
    public function flagLottoPerArticoli(array $codici): array;
}
