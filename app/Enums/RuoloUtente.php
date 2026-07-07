<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ruoli applicativi (§7). Il valore stringa e' persistito su users.ruolo.
 */
enum RuoloUtente: string
{
    case Operatore = 'operatore';
    case Backoffice = 'backoffice';
    case Pianificazione = 'pianificazione';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Operatore => 'Operatore di reparto',
            self::Backoffice => 'Backoffice di produzione',
            self::Pianificazione => 'Responsabile pianificazione',
            self::Admin => 'Amministratore',
        };
    }

    /** Gli operatori accedono via PIN su tablet; gli altri via email+password. */
    public function usaPin(): bool
    {
        return $this === self::Operatore;
    }
}
