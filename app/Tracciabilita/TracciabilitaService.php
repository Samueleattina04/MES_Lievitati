<?php

declare(strict_types=1);

namespace App\Tracciabilita;

use App\Tracciabilita\Contracts\MovimentiLottoSourceInterface;

/**
 * Ricostruisce carichi/scarichi di un lotto risalendo l'intera distinta dai movimenti ESOLVER (§6-bis).
 * Dal lotto del prodotto finito: consumi (scarichi) dei componenti; ogni componente-semilavorato viene
 * espanso ricorsivamente (il suo lotto è a sua volta un "lotto prodotto"). Fino a materie prime foglia.
 */
final class TracciabilitaService
{
    public function __construct(
        private readonly MovimentiLottoSourceInterface $source,
        private readonly int $maxLivelli = 12,
        private readonly int $maxNodi = 500,
    ) {}

    /**
     * @return array{lotto:string, trovato:bool, nodo:?array<string,mixed>, movimenti:list<array<string,mixed>>, produzioni:list<array<string,mixed>>}
     */
    public function albero(string $lottoRadice): array
    {
        $lottoRadice = trim($lottoRadice);
        if ($lottoRadice === '') {
            return ['lotto' => '', 'trovato' => false, 'nodo' => null, 'movimenti' => [], 'produzioni' => []];
        }

        // BFS: raccoglie tutti i consumi risalendo per lotto-prodotto, livello per livello.
        $visitati = [];
        $frontiera = [$lottoRadice];
        /** @var array<string,list<MovimentoLotto>> $consumiPerLotto */
        $consumiPerLotto = [];
        $livello = 0;

        while ($frontiera !== [] && $livello < $this->maxLivelli) {
            $batch = array_values(array_filter($frontiera, fn ($l) => ! isset($visitati[$l])));
            if ($batch === []) {
                break;
            }
            foreach ($batch as $l) {
                $visitati[$l] = true;
            }

            $prossima = [];
            foreach ($this->source->consumiPerProdotti($batch) as $c) {
                $consumiPerLotto[$c->lottoProdotto][] = $c;
                // Si espandono SOLO i lotti che sono lotti di produzione interni: i lotti d'acquisto/
                // placeholder (es. "1", "10/12/2025") sono foglie. Espanderli farebbe esplodere la
                // ricerca (valori jolly su ~3M movimenti senza indice su RifLottoPFAlfanum).
                if ($c->lotto !== '' && ! isset($visitati[$c->lotto]) && self::pareLottoProduzione($c->lotto)) {
                    $prossima[$c->lotto] = true;
                }
            }
            if (count($visitati) >= $this->maxNodi) {
                break; // guardia anti-esplosione
            }
            $frontiera = array_keys($prossima);
            $livello++;
        }

        // Carichi (versamenti) di tutti i lotti prodotti coinvolti (radice + semilavorati).
        $lottiProdotti = array_values(array_unique(array_merge([$lottoRadice], array_keys($consumiPerLotto))));
        /** @var array<string,MovimentoLotto> $carichi */
        $carichi = [];
        foreach ($this->source->carichiPerLotti($lottiProdotti) as $car) {
            $carichi[$car->lotto] = $car;
        }

        // Fattore di proporzione per lotto di produzione: quota del batch usata da questo lotto finito,
        // per scaricare solo le quantita' realmente usate (impasto condiviso tra piu' finiti -> non si
        // scarica l'impasto intero per ognuno).
        $fattore = $this->fattoriProduzione($lottoRadice, $consumiPerLotto, $carichi);

        $nodo = $this->costruisciNodo($lottoRadice, $consumiPerLotto, $carichi, $fattore, []);
        $trovato = isset($carichi[$lottoRadice]) || ! empty($consumiPerLotto[$lottoRadice]);

        return [
            'lotto' => $lottoRadice,
            'trovato' => $trovato,
            'nodo' => $nodo,
            'movimenti' => $this->flatten($consumiPerLotto, $carichi),
            'produzioni' => $this->produzioni($consumiPerLotto, $carichi, $fattore),
        ];
    }

    /**
     * Fattore di proporzione per ogni lotto di produzione = quota del suo batch attribuibile al lotto
     * finito radice. Radice = 1. Per un semilavorato C consumato dal padre P:
     *   fattore[C] = fattore[P] * (quantita di C usata da P) / (quantita di C prodotta = suo carico).
     * Cosi' si scaricano, per ogni lotto finito, SOLO le materie prime realmente usate (es. l'impasto
     * usato al 25% -> 25% delle sue materie prime), evitando di scaricare l'impasto intero per ogni
     * lotto finito che lo condivide. Se il carico non e' affidabile (assente/<=0) si usa la sola
     * quantita' consumata (modello "1 batch = 1 intero", coerente coi dati ESOLVER a frazione).
     *
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @return array<string,float>
     */
    private function fattoriProduzione(string $lottoRadice, array $consumiPerLotto, array $carichi): array
    {
        $fattore = [$lottoRadice => 1.0];
        $coda = [$lottoRadice];

        while ($coda !== []) {
            $p = array_shift($coda);
            $fp = $fattore[$p] ?? 1.0;
            foreach ($consumiPerLotto[$p] ?? [] as $c) {
                // Solo i lotti di produzione (che hanno propri consumi) si proporzionano; prima occ. vince.
                if (! isset($consumiPerLotto[$c->lotto]) || isset($fattore[$c->lotto])) {
                    continue;
                }
                $prodotta = ($carichi[$c->lotto] ?? null)?->quantita;
                $quota = ($prodotta !== null && $prodotta > 0)
                    ? (float) $c->quantita / (float) $prodotta
                    : (float) $c->quantita;
                $fattore[$c->lotto] = $fp * $quota;
                $coda[] = $c->lotto;
            }
        }

        return $fattore;
    }

    /**
     * Un elemento per LOTTO DI PRODUZIONE (finito + semilavorati) con i suoi componenti: base per
     * l'export nel tracciato Omni (una riga per lotto, componenti in orizzontale).
     *
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @param  array<string,float>  $fattore  quota per lotto di produzione (vedi fattoriProduzione)
     * @return list<array<string,mixed>>
     */
    private function produzioni(array $consumiPerLotto, array $carichi, array $fattore): array
    {
        $out = [];
        foreach ($consumiPerLotto as $lottoProdotto => $consumi) {
            $carico = $carichi[$lottoProdotto] ?? null;
            $f = $fattore[$lottoProdotto] ?? 1.0;
            $out[] = [
                'lotto' => $lottoProdotto,
                'articolo' => $carico?->articolo ?? ($consumi[0]->articoloProdotto ?? ''),
                'data' => $carico?->data ?? ($consumi[0]->data ?? null),
                'quantita_prodotta' => $carico?->quantita,
                // Quantita' riscalata per la quota usata dal lotto finito (no doppio scarico impasti).
                'componenti' => array_map(static fn (MovimentoLotto $c) => [
                    'articolo' => $c->articolo,
                    'lotto' => $c->lotto,
                    'quantita' => (float) $c->quantita * $f,
                    'um' => $c->um,
                ], $consumi),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @param  array<string,float>  $fattore  quota per lotto di produzione (vedi fattoriProduzione)
     * @param  list<string>  $percorso
     * @return array<string,mixed>|null
     */
    private function costruisciNodo(string $lotto, array $consumiPerLotto, array $carichi, array $fattore, array $percorso): ?array
    {
        if (in_array($lotto, $percorso, true)) {
            return null; // ciclo non atteso: interrompe
        }
        $percorso[] = $lotto;

        $carico = $carichi[$lotto] ?? null;
        $consumi = $consumiPerLotto[$lotto] ?? [];
        $f = $fattore[$lotto] ?? 1.0;

        $articolo = $carico?->articolo ?? '';
        if ($articolo === '' && $consumi !== []) {
            $articolo = $consumi[0]->articoloProdotto;
        }

        $componenti = [];
        foreach ($consumi as $c) {
            $figlio = isset($consumiPerLotto[$c->lotto])
                ? $this->costruisciNodo($c->lotto, $consumiPerLotto, $carichi, $fattore, $percorso)
                : null;

            $componenti[] = [
                'articolo' => $c->articolo,
                'lotto' => $c->lotto,
                // Quantita' riscalata per la quota usata dal lotto finito (coerente con l'export Omni).
                'quantita' => (float) $c->quantita * $f,
                'um' => $c->um,
                'magazzino' => $c->magazzino,
                'data' => $c->data,
                'causale' => $c->causale,
                'semilavorato' => $figlio !== null,
                'figlio' => $figlio,
            ];
        }

        return [
            'articolo' => $articolo,
            'lotto' => $lotto,
            'quantita_prodotta' => $carico?->quantita,
            'um' => $carico?->um,
            'data' => $carico?->data,
            'componenti' => $componenti,
        ];
    }

    /**
     * Lista piatta di TUTTI i movimenti (carichi + scarichi) — per la tabella e il futuro export Omni.
     *
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @return list<array<string,mixed>>
     */
    private function flatten(array $consumiPerLotto, array $carichi): array
    {
        $out = [];
        foreach ($carichi as $c) {
            $out[] = $c->toArray();
        }
        foreach ($consumiPerLotto as $lista) {
            foreach ($lista as $c) {
                $out[] = $c->toArray();
            }
        }

        // Ordinamento cronologico fatto qui (una volta) invece che in SQL a ogni scansione:
        // la data e' gia' formattata 'd/m/Y H.i.s', quindi si riparsa per confrontarla.
        usort($out, static fn (array $a, array $b) => self::chiaveData($a['data'] ?? null) <=> self::chiaveData($b['data'] ?? null));

        return $out;
    }

    /** Chiave ordinabile (timestamp) da una data 'd/m/Y H.i.s'; le date assenti vanno in fondo. */
    private static function chiaveData(?string $data): int
    {
        if ($data === null || $data === '') {
            return PHP_INT_MAX;
        }
        $dt = \DateTime::createFromFormat('d/m/Y H.i.s', $data);

        return $dt !== false ? $dt->getTimestamp() : PHP_INT_MAX;
    }

    /**
     * Vero se il lotto e' un lotto di PRODUZIONE interno, quindi da espandere risalendo la distinta.
     * I lotti di produzione aziendali hanno la struttura col trattino (<prodotto>-<giorno><anno><prog>,
     * es. 6412-27826111). I lotti d'acquisto/placeholder — numeri nudi ("1"), date ("10/12/2025"),
     * codici fornitore ("L120618300", "3126004810") — NON hanno quella forma: sono foglie e non si
     * espandono. Fondamentale per le performance: espandere un valore come "1" lo userebbe come jolly
     * su ~3M movimenti (colonna RifLottoPFAlfanum non indicizzata) facendo esplodere la ricerca.
     */
    private static function pareLottoProduzione(string $lotto): bool
    {
        return (bool) preg_match('/^[^\s\/]+-[^\s\/]+$/', trim($lotto));
    }
}
