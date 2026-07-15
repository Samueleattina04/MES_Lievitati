<?php

declare(strict_types=1);

namespace App\Stock;

use App\Models\LottoProdotto;
use App\Stock\Contracts\LottoSemilavoratoSourceInterface;

/**
 * Implementazione di default (§5.3, change #3): un lotto di semilavorato e' "gia' esistente" se
 * risulta gia' registrato in `lotti_prodotto` (prodotto in un ordine precedente, con i suoi
 * componenti gia' scaricati). Match su articolo + lotto.
 *
 * Sostituibile con una sorgente diversa (es. giacenza semilavorati su magazzino dedicato) senza
 * modificare la logica: vedi mes.semilavorato.sorgente_lotti in config/mes.php.
 */
final class LottiProdottoSemilavoratoSource implements LottoSemilavoratoSourceInterface
{
    public function esisteLotto(string $articolo, string $lotto): bool
    {
        $articolo = trim($articolo);
        $lotto = trim($lotto);

        if ($articolo === '' || $lotto === '') {
            return false;
        }

        return LottoProdotto::query()
            ->where('articolo_codice', $articolo)
            ->where('lotto', $lotto)
            ->exists();
    }
}
