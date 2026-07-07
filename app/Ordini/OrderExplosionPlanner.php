<?php

declare(strict_types=1);

namespace App\Ordini;

use App\Bom\BomExplosion;
use App\Bom\BomRow;
use App\Ordini\Planning\PhasePlan;
use App\Ordini\Planning\PlannedMaterial;
use App\Ordini\Planning\PlannedPhase;
use Illuminate\Support\Collection;

/**
 * Servizio PURO (nessun accesso al DB) che trasforma la distinta esplosa in un piano di fasi (§3, §4.1):
 *
 *  - una fase per ciascun nodo prodotto (radice + semilavorati); i nodi condivisi -> UNA sola fase
 *    con quantita' = somma su tutte le occorrenze (prodotto una volta, poi ripartito, §5-bis);
 *  - materiali di una fase = figli diretti del nodo, con le righe duplicate per percorso sommate;
 *  - precedenze bottom-up = i figli che sono a loro volta nodi prodotti;
 *  - quantita' pianificate = quantita' normalizzata per unita' * quantita' d'ordine.
 *
 * Essendo puro e' interamente unit-testabile senza database.
 */
final class OrderExplosionPlanner
{
    public function __construct(
        private readonly int $decimali = 6,
    ) {}

    public function plan(BomExplosion $esplosione, float $quantitaOrdine): PhasePlan
    {
        $fasi = [];

        foreach ($esplosione->codiciNodiProdotti() as $codice) {
            $occorrenze = $esplosione->occorrenze($codice);
            $primaOcc = $occorrenze->first();

            $materiali = $this->materiali($esplosione, $codice, $quantitaOrdine);

            $fasi[] = new PlannedPhase(
                articoloCodice: $codice,
                descrizione: $this->descrizioneNodo($esplosione, $codice, $primaOcc),
                udm: $primaOcc?->udm,
                quantitaPianificata: $this->arrotonda($occorrenze->sum(fn (BomRow $r) => $r->qtaPerUnita) * $quantitaOrdine),
                livello: (int) $occorrenze->max(fn (BomRow $r) => $r->livello),
                isCondiviso: $esplosione->eCondiviso($codice),
                materiali: $materiali,
                fasiFiglieCodici: $this->codiciFigliProdotti($esplosione, $codice),
            );
        }

        // Ordinamento deterministico: prima i nodi piu' profondi (bottom-up), poi per codice.
        usort($fasi, function (PlannedPhase $a, PlannedPhase $b) {
            return [$b->livello, $a->articoloCodice] <=> [$a->livello, $b->articoloCodice];
        });

        return new PhasePlan($esplosione->articoloRadice, $quantitaOrdine, $fasi);
    }

    /** @return list<PlannedMaterial> */
    private function materiali(BomExplosion $esplosione, string $nodo, float $quantitaOrdine): array
    {
        return $esplosione->figliDiretti($nodo)
            ->groupBy(fn (BomRow $r) => $r->articolo)
            ->map(function (Collection $righe, string $articolo) use ($quantitaOrdine) {
                $prima = $righe->first();

                return new PlannedMaterial(
                    articoloCodice: $articolo,
                    descrizione: $righe->map(fn (BomRow $r) => $r->descrizione)->filter()->first(),
                    udm: $prima->udm,
                    quantitaPianificata: $this->arrotonda($righe->sum(fn (BomRow $r) => $r->qtaPerUnita) * $quantitaOrdine),
                    eSemilavorato: $righe->contains(fn (BomRow $r) => $r->isProdotto),
                );
            })
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function codiciFigliProdotti(BomExplosion $esplosione, string $nodo): array
    {
        return $esplosione->figliDiretti($nodo)
            ->filter(fn (BomRow $r) => $r->isProdotto)
            ->map(fn (BomRow $r) => $r->articolo)
            ->unique()
            ->values()
            ->all();
    }

    private function descrizioneNodo(BomExplosion $esplosione, string $codice, ?BomRow $primaOcc): ?string
    {
        return $esplosione->occorrenze($codice)
            ->map(fn (BomRow $r) => $r->descrizione)
            ->filter()
            ->first() ?? $primaOcc?->descrizione;
    }

    private function arrotonda(float $valore): float
    {
        return round($valore, $this->decimali);
    }
}
