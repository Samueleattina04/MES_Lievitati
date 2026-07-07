<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stati di una fase o di un suo step (§5). Una fase e' chiusa quando tutti i suoi step lo sono.
 */
enum StatoFase: string
{
    case DaLavorare = 'da_lavorare';
    case InCorso = 'in_corso';
    case Chiusa = 'chiusa';

    public function label(): string
    {
        return match ($this) {
            self::DaLavorare => 'Da lavorare',
            self::InCorso => 'In corso',
            self::Chiusa => 'Chiusa',
        };
    }

    /** Colore di stato per la UI operatore (§8). */
    public function colore(): string
    {
        return match ($this) {
            self::DaLavorare => 'grigio',
            self::InCorso => 'giallo',
            self::Chiusa => 'verde',
        };
    }
}
