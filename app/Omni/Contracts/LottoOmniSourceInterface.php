<?php

declare(strict_types=1);

namespace App\Omni\Contracts;

/**
 * Sorgente dei lotti Omni per la traduzione dei lotti ESOLVER (§6-bis). Dato l'articolo e il lotto come
 * li conosce ESOLVER, restituisce i lotti interni Omni CON GIACENZA reale > 0, ordinati FIFO (piu'
 * vecchi prima) e con la loro giacenza, cosi' una quantita' da scaricare puo' essere ripartita su piu'
 * lotti senza mandare l'import Omni in negativo. In coda ci sono i lotti dello stesso articolo (fallback)
 * non gia' inclusi nel match esatto. Elenco vuoto se non c'e' corrispondenza (es. semilavorati).
 */
interface LottoOmniSourceInterface
{
    /** @return list<array{lotto:string, giacenza:float, data:string}> lotti Omni con giacenza, FIFO */
    public function lottiConGiacenza(string $articoloEsolver, string $lottoEsolver): array;
}
