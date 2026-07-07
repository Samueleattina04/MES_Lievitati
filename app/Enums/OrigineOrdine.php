<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origine di un ordine di produzione (§4.4, §13).
 */
enum OrigineOrdine: string
{
    case Manuale = 'manuale';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manuale => 'Inserimento manuale',
            self::Import => 'Importato da gestionale',
        };
    }
}
