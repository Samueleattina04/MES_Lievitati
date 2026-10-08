<?php

declare(strict_types=1);

namespace App\Check;

use App\Check\Contracts\ArticoliSourceInterface;

/**
 * Sorgente anagrafica articoli per sviluppo/test (§ Check): mappa in memoria codice => metadati.
 * Per comodita' il valore puo' essere una semplice stringa (= descrizione) oppure un array
 * {descrizione, tabella, id}. In locale non ci sono i gestionali, quindi di default e' vuota.
 */
final class FixtureArticoliAdapter implements ArticoliSourceInterface
{
    /** @param array<string,string|array<string,mixed>> $articoli codice => descrizione | {descrizione,tabella,id} */
    public function __construct(private readonly array $articoli = []) {}

    public function articoli(): array
    {
        $out = [];
        foreach ($this->articoli as $codice => $v) {
            $out[(string) $codice] = is_array($v)
                ? [
                    'descrizione' => (string) ($v['descrizione'] ?? ''),
                    'tabella' => (string) ($v['tabella'] ?? ''),
                    'id' => (string) ($v['id'] ?? ''),
                ]
                : ['descrizione' => (string) $v, 'tabella' => '', 'id' => ''];
        }

        return $out;
    }
}
