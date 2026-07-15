<?php

declare(strict_types=1);

namespace App\Stock\Contracts;

/**
 * Sorgente per riconoscere un lotto di semilavorato "gia' esistente a sistema" (§5.3, change #3).
 *
 * [DA CONFERMARE con il committente] la sorgente reale: lotti_prodotto storici e/o giacenza di
 * semilavorato su un magazzino dedicato. L'implementazione e' dietro questo punto di estensione
 * (config mes.semilavorato.sorgente_lotti) cosi' da poter cambiare sorgente senza toccare la
 * logica di dominio. Default: i lotti_prodotto gia' registrati (LottiProdottoSemilavoratoSource).
 */
interface LottoSemilavoratoSourceInterface
{
    /** Il lotto indicato per questo articolo esiste gia' a sistema (prelevabile da stock)? */
    public function esisteLotto(string $articolo, string $lotto): bool;
}
