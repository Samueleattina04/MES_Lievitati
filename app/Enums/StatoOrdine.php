<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stati del ciclo di vita di un ordine di produzione (§5).
 */
enum StatoOrdine: string
{
    case Aperto = 'aperto';
    case InLavorazione = 'in_lavorazione';
    case Completato = 'completato';   // tutte le fasi chiuse: pronto per l'export
    case Esportato = 'esportato';     // tracciati generati: non piu' modificabile

    public function label(): string
    {
        return match ($this) {
            self::Aperto => 'Aperto',
            self::InLavorazione => 'In lavorazione',
            self::Completato => 'Completato',
            self::Esportato => 'Esportato',
        };
    }

    public function modificabile(): bool
    {
        return $this !== self::Esportato;
    }
}
