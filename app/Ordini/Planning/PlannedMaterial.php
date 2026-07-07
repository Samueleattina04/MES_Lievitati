<?php

declare(strict_types=1);

namespace App\Ordini\Planning;

/**
 * Materiale pianificato di una fase (figlio diretto del nodo). Risultato del planner PURO:
 * non conosce flag_lotto (attributo di anagrafica) — quello lo assegna il materializer.
 */
final readonly class PlannedMaterial
{
    public function __construct(
        public string $articoloCodice,
        public ?string $descrizione,
        public ?string $udm,
        public float $quantitaPianificata,
        public bool $eSemilavorato,
    ) {}
}
