<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;

/**
 * Unisce piu' sorgenti di anagrafica articoli in un unico elenco (§ Check). Serve quando un gestionale
 * tiene gli articoli su piu' tabelle (es. Omni: T_INGREDIENTI + T_PRODOTTI). A parita' di codice vince
 * la prima occorrenza; la descrizione viene completata se la prima era vuota.
 */
final class CompositeArticoliSource implements ArticoliSourceInterface
{
    /** @var list<ArticoliSourceInterface> */
    private array $sorgenti;

    public function __construct(ArticoliSourceInterface ...$sorgenti)
    {
        $this->sorgenti = array_values($sorgenti);
    }

    public function articoli(): array
    {
        $out = [];
        foreach ($this->sorgenti as $sorgente) {
            foreach ($sorgente->articoli() as $codice => $descrizione) {
                if (! array_key_exists($codice, $out) || ($out[$codice] === '' && $descrizione !== '')) {
                    $out[$codice] = $descrizione;
                }
            }
        }

        return $out;
    }
}
