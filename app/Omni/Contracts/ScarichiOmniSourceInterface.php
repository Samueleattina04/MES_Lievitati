<?php

declare(strict_types=1);

namespace App\Omni\Contracts;

/**
 * Sorgente per sapere se un lotto di PRODUZIONE (prodotto finito) ha gia' scarichi registrati in Omni
 * (§6-bis, anti doppio-scarico). Il legame e': lotto PF = `T_Linkfattlotti.[Lotto entrata]` (con
 * `IDProduzione` valorizzato) -> `IDProduzione` -> righe in `T_ContatoreLEXLP`. Disaccoppia il dominio
 * dalla sorgente reale (Access ODBC) o dai fixture (dev/test).
 */
interface ScarichiOmniSourceInterface
{
    /**
     * Per i lotti di produzione indicati, quanti scarichi risultano gia' presenti in Omni.
     * Ritorna SOLO i lotti con scarichi > 0 (gli altri sono assenti dalla mappa = 0, mai scaricati).
     *
     * @param  list<string>  $lottiProduzione  lotti PF (formato produzione aziendale, es. "6412-27826111")
     * @return array<string,int>  mappa lottoProduzione => numero di scarichi
     */
    public function scarichiPerProduzioni(array $lottiProduzione): array;
}
