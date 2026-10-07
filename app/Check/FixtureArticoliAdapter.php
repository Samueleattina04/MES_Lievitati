<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;

/**
 * Sorgente anagrafica articoli per sviluppo/test (§ Check): mappa in memoria codice => descrizione.
 * In locale non ci sono i gestionali, quindi di default e' vuota.
 */
final class FixtureArticoliAdapter implements ArticoliSourceInterface
{
    /** @param array<string,string> $articoli codice => descrizione */
    public function __construct(private readonly array $articoli = []) {}

    public function articoli(): array
    {
        return $this->articoli;
    }
}
