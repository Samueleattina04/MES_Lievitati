<?php

declare(strict_types=1);

namespace App\Check\Contracts;

/**
 * Sorgente dell'anagrafica articoli di un gestionale (§ Check). Restituisce la mappa
 * "codice articolo" => metadati {descrizione, tabella, id}. 'tabella' e 'id' servono lato Omni per
 * indicare, nel foglio "Omni non in Esolver", da quale tabella arriva l'articolo e il suo id d'origine
 * (lato ESOLVER non sono usati). Il codice e' la chiave di confronto fra i due gestionali.
 */
interface ArticoliSourceInterface
{
    /** @return array<string,array{descrizione:string, tabella:string, id:string}> codice => metadati */
    public function articoli(): array;
}
