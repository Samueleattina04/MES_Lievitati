<?php

declare(strict_types=1);

namespace App\Produzione;

/**
 * Confronto di quantita' entro una tolleranza assoluta (§6 multi-lotto, §5-bis split).
 * Puro e unit-testabile. Un piccolo epsilon assorbe gli errori di rappresentazione float.
 */
final class Tolleranza
{
    private const EPSILON = 1e-9;

    public static function entro(float $atteso, float $effettivo, float $tolleranza): bool
    {
        return abs($atteso - $effettivo) <= $tolleranza + self::EPSILON;
    }
}
