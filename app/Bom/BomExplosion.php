<?php

declare(strict_types=1);

namespace App\Bom;

use Illuminate\Support\Collection;

/**
 * Risultato dell'esplosione di una distinta: l'insieme (piatto) delle righe padre->figlio.
 * Espone gli helper di dominio necessari a generare fasi e materiali (§3, §5-bis):
 * nodi prodotti, figli diretti, individuazione dei nodi condivisi (punti di split).
 */
final class BomExplosion
{
    /** @var Collection<int,BomRow> */
    private Collection $righe;

    /** @param iterable<BomRow> $righe */
    public function __construct(
        public readonly string $articoloRadice,
        iterable $righe,
    ) {
        $this->righe = collect($righe);
    }

    /** @return Collection<int,BomRow> */
    public function righe(): Collection
    {
        return $this->righe;
    }

    public function vuota(): bool
    {
        return $this->righe->isEmpty();
    }

    public function rigaRadice(): ?BomRow
    {
        return $this->righe->firstWhere(fn (BomRow $r) => $r->eRadice());
    }

    /**
     * Codici dei nodi prodotti (radice + semilavorati), ciascuno dei quali genera una fase.
     *
     * @return list<string>
     */
    public function codiciNodiProdotti(): array
    {
        return $this->righe
            ->filter(fn (BomRow $r) => $r->isProdotto)
            ->map(fn (BomRow $r) => $r->articolo)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Figli diretti (materiali) di un nodo, su TUTTE le sue occorrenze nell'albero.
     *
     * @return Collection<int,BomRow>
     */
    public function figliDiretti(string $articolo): Collection
    {
        return $this->righe->filter(fn (BomRow $r) => $r->articoloPadre === $articolo)->values();
    }

    /**
     * Occorrenze di un articolo come nodo (una riga per ciascun padre che lo consuma).
     *
     * @return Collection<int,BomRow>
     */
    public function occorrenze(string $articolo): Collection
    {
        return $this->righe->filter(fn (BomRow $r) => $r->articolo === $articolo)->values();
    }

    /**
     * Padri distinti (non nulli) che consumano l'articolo.
     *
     * @return list<string>
     */
    public function padriDistinti(string $articolo): array
    {
        return $this->occorrenze($articolo)
            ->map(fn (BomRow $r) => $r->articoloPadre)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Un nodo e' condiviso (punto di split, §5-bis) se e' consumato da piu' di un padre distinto.
     */
    public function eCondiviso(string $articolo): bool
    {
        return count($this->padriDistinti($articolo)) > 1;
    }

    /** Profondita' massima a cui compare l'articolo (per ordinamento/precedenze, §3). */
    public function livelloMassimo(string $articolo): int
    {
        return (int) $this->occorrenze($articolo)->max('livello');
    }
}
