<?php

declare(strict_types=1);

namespace App\Check\Contracts;

/**
 * Sorgente dell'anagrafica articoli di un gestionale (§ Check). Restituisce la mappa
 * "codice articolo" => "descrizione" (descrizione eventualmente vuota). Il codice e' quello di ESOLVER
 * (su Omni e' il campo CodArtEsolver), cosi' i due elenchi sono confrontabili sulla stessa chiave.
 */
interface ArticoliSourceInterface
{
    /** @return array<string,string> codice => descrizione */
    public function articoli(): array;
}
