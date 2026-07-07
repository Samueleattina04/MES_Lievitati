<?php

declare(strict_types=1);

namespace App\Ordini\Planning;

/**
 * Fase pianificata = un nodo prodotto dell'ordine (§3). Una sola istanza per articolo prodotto:
 * i nodi condivisi hanno isCondiviso=true e quantita' pari alla somma su tutte le occorrenze.
 */
final readonly class PlannedPhase
{
    /**
     * @param list<PlannedMaterial> $materiali
     * @param list<string> $fasiFiglieCodici codici dei nodi componenti prodotti (precedenze bottom-up)
     */
    public function __construct(
        public string $articoloCodice,
        public ?string $descrizione,
        public ?string $udm,
        public float $quantitaPianificata,
        public int $livello,
        public bool $isCondiviso,
        public array $materiali,
        public array $fasiFiglieCodici,
    ) {}
}
