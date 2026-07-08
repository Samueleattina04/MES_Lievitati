<?php

declare(strict_types=1);

namespace App\Stock;

/**
 * Ripartizione FIFO PURA (§5.2): dato l'elenco dei lotti disponibili (gia' ordinati FIFO) e la
 * quantita' richiesta, pre-compila le righe lotto consumando prima i lotti piu' vecchi, passando
 * al successivo quando uno non basta. Nessun accesso al DB: interamente unit-testabile.
 */
final class FifoAllocator
{
    /**
     * @param list<StockLotto> $lottiDisponibili gia' ordinati in ottica FIFO
     * @return list<array{lotto:string, quantita:float}> proposta (puo' essere parziale se la
     *         giacenza totale non copre la quantita' richiesta)
     */
    public static function proponi(array $lottiDisponibili, float $quantitaRichiesta, int $decimali = 6): array
    {
        $proposta = [];
        $residuo = round($quantitaRichiesta, $decimali);

        foreach ($lottiDisponibili as $lotto) {
            if ($residuo <= 0) {
                break;
            }
            $prendi = min($lotto->quantita, $residuo);
            if ($prendi <= 0) {
                continue;
            }
            $prendi = round($prendi, $decimali);
            $proposta[] = ['lotto' => $lotto->lotto, 'quantita' => $prendi];
            $residuo = round($residuo - $prendi, $decimali);
        }

        return $proposta;
    }

    /**
     * Somma coperta da una proposta.
     *
     * @param list<array{lotto:string, quantita:float}> $proposta
     */
    public static function totale(array $proposta): float
    {
        return array_sum(array_map(fn ($r) => (float) $r['quantita'], $proposta));
    }
}
