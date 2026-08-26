<?php

declare(strict_types=1);

namespace App\Tracciabilita\Contracts;

use App\Tracciabilita\MovimentoLotto;

/**
 * Sorgente dei movimenti di magazzino per lotto (§6-bis). Disaccoppia il dominio della tracciabilita'
 * dalla sorgente reale (ESOLVER su SQL Server) o dai fixture (dev/test).
 */
interface MovimentiLottoSourceInterface
{
    /**
     * Scarichi (consumi da produzione) dei lotti-prodotto indicati: per ogni lotto di prodotto in
     * ingresso, i componenti consumati (articolo + lotto + quantita').
     *
     * @param  list<string>  $lottiProdotto
     * @return list<MovimentoLotto>
     */
    public function consumiPerProdotti(array $lottiProdotto): array;

    /**
     * Carichi (versamenti da produzione) dei lotti indicati: la riga di versamento del prodotto.
     *
     * @param  list<string>  $lotti
     * @return list<MovimentoLotto>
     */
    public function carichiPerLotti(array $lotti): array;
}
