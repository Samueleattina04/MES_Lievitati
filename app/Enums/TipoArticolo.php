<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Natura dell'articolo nella distinta (§3): foglia acquistata vs nodo prodotto.
 */
enum TipoArticolo: string
{
    case Acquistato = 'acquistato'; // foglia: si consuma soltanto
    case Prodotto = 'prodotto';     // ha una sotto-distinta: genera una fase

    public function label(): string
    {
        return match ($this) {
            self::Acquistato => 'Acquistato',
            self::Prodotto => 'Prodotto',
        };
    }
}
