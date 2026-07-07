<?php

declare(strict_types=1);

namespace App\Ordini\Planning;

/**
 * Piano di produzione derivato dall'esplosione di un ordine: l'elenco delle fasi da generare
 * (con materiali e precedenze) e le quantita' gia' moltiplicate per la quantita' d'ordine.
 * Prodotto da OrderExplosionPlanner (puro) e consumato dal materializer (persistenza).
 */
final readonly class PhasePlan
{
    /** @param list<PlannedPhase> $fasi */
    public function __construct(
        public string $articoloRadice,
        public float $quantitaOrdine,
        public array $fasi,
    ) {}

    public function conta(): int
    {
        return count($this->fasi);
    }

    public function fase(string $articoloCodice): ?PlannedPhase
    {
        foreach ($this->fasi as $fase) {
            if ($fase->articoloCodice === $articoloCodice) {
                return $fase;
            }
        }

        return null;
    }

    /** @return list<PlannedPhase> */
    public function fasiCondivise(): array
    {
        return array_values(array_filter($this->fasi, fn (PlannedPhase $f) => $f->isCondiviso));
    }

    /** @return list<string> */
    public function codiciFasi(): array
    {
        return array_map(fn (PlannedPhase $f) => $f->articoloCodice, $this->fasi);
    }
}
