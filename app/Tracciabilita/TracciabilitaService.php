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
        private readonly int $decimaliQuantita = 3,
        private readonly bool $arrotondaEccesso = false,
    ) {}

    /**
     * @param  bool  $proporziona  Se true (default) riscala le materie prime dei semilavorati alla quota
     *                             realmente usata dal lotto finito; se false mostra le quantita' grezze
     *                             (impasto intero). Utile per confrontare i due comportamenti.
     * @return array{lotto:string, trovato:bool, nodo:?array<string,mixed>, movimenti:list<array<string,mixed>>, produzioni:list<array<string,mixed>>}
     */
    public function albero(string $lottoRadice, bool $proporziona = true): array
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

        // Fattore di proporzione per lotto di produzione = quota del suo batch attribuibile al lotto
        // finito. PER-OCCORRENZA: se un semilavorato e' consumato piu' volte (es. 5 KG e 155 KG), ogni
        // consumo ha il suo fattore (nell'albero); per l'export si SOMMANO (totale attribuito al
        // finito). Se $proporziona e' false -> nessun riscalo (quantita' grezze, impasto intero).
        $fattoreTotale = [];
        if ($proporziona) {
            $fattoreTotale = [$lottoRadice => 1.0];
            $this->accumulaFattori($lottoRadice, 1.0, $consumiPerLotto, $carichi, $fattoreTotale, []);
        }

        $nodo = $this->costruisciNodo($lottoRadice, 1.0, $proporziona, $consumiPerLotto, $carichi, []);
        $trovato = isset($carichi[$lottoRadice]) || ! empty($consumiPerLotto[$lottoRadice]);

        return [
            'lotto' => $lottoRadice,
            'trovato' => $trovato,
            'nodo' => $nodo,
            'movimenti' => $this->flatten($consumiPerLotto, $carichi),
            'produzioni' => $this->produzioni($consumiPerLotto, $carichi, $fattoreTotale),
        ];
    }

    /**
     * Accumula, per ogni lotto di produzione, il fattore TOTALE attribuibile al lotto finito radice:
     * la SOMMA dei fattori di tutte le occorrenze (un semilavorato consumato piu' volte da' piu'
     * contributi). Per un consumo di C dentro P col fattore corrente $fattore:
     *   quota = quantita di C usata / quantita di C prodotta (carico);  contributo = $fattore * quota;
     * poi si ricorre in C con quel contributo. Serve all'export (una riga per lotto, quantita' =
     * materie * fattore totale): se un impasto e' consumato al 25% da un finito -> 25% delle sue
     * materie; se e' consumato due volte (5 + 155 su 160) -> 100%. Il controllo su $percorso evita i
     * cicli. Se il carico non e' affidabile (assente/<=0) si usa la sola quantita' consumata.
     *
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @param  array<string,float>  $totale  (per riferimento) mappa lotto => fattore totale
     * @param  list<string>  $percorso
     */
    private function accumulaFattori(string $lotto, float $fattore, array $consumiPerLotto, array $carichi, array &$totale, array $percorso): void
    {
        if (in_array($lotto, $percorso, true)) {
            return; // ciclo non atteso: interrompe
        }
        $percorso[] = $lotto;

        foreach ($consumiPerLotto[$lotto] ?? [] as $c) {
            // Solo i lotti di produzione (che hanno propri consumi) si proporzionano oltre.
            if (! isset($consumiPerLotto[$c->lotto])) {
                continue;
            }
            $contributo = $fattore * $this->quota($c, $carichi);
            $totale[$c->lotto] = ($totale[$c->lotto] ?? 0.0) + $contributo;
            $this->accumulaFattori($c->lotto, $contributo, $consumiPerLotto, $carichi, $totale, $percorso);
        }
    }

    /**
     * Quota di un consumo = quantita' consumata / quantita' prodotta (carico del componente). Se il
     * carico non e' affidabile (assente/<=0) si usa la sola quantita' consumata (modello "1 batch = 1
     * intero", coerente coi dati ESOLVER a frazione).
     *
     * @param  array<string,MovimentoLotto>  $carichi
     */
    private function quota(MovimentoLotto $c, array $carichi): float
    {
        $prodotta = ($carichi[$c->lotto] ?? null)?->quantita;

        return ($prodotta !== null && $prodotta > 0)
            ? (float) $c->quantita / (float) $prodotta
            : (float) $c->quantita;
    }

    /**
     * Un elemento per LOTTO DI PRODUZIONE (finito + semilavorati) con i suoi componenti: base per
     * l'export nel tracciato Omni (una riga per lotto, componenti in orizzontale).
     *
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @param  array<string,float>  $fattore  fattore TOTALE per lotto di produzione (vedi accumulaFattori)
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
                'componenti' => array_map(fn (MovimentoLotto $c) => [
                    'articolo' => $c->articolo,
                    'lotto' => $c->lotto,
                    'quantita' => $this->arrotonda((float) $c->quantita * $f),
                    'um' => $c->um,
                ], $consumi),
            ];
        }

        return $out;
    }

    /**
     * Costruisce il nodo dell'albero con proporzione PER-OCCORRENZA: $fattore e' il fattore del nodo
     * corrente (frazione del suo batch attribuita al finito lungo QUESTO percorso); le quantita' dei
     * componenti sono scalate per $fattore, e ogni figlio-semilavorato si ricorre con
     * $fattore * (consumato/prodotto) proprio di quel consumo. Cosi' lo stesso semilavorato consumato
     * due volte (es. 5 KG e 155 KG) mostra due sotto-alberi con quantita' diverse. Se $proporziona e'
     * false il fattore resta 1 ovunque (quantita' grezze, impasto intero).
     *
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @param  list<string>  $percorso
     * @return array<string,mixed>|null
     */
    private function costruisciNodo(string $lotto, float $fattore, bool $proporziona, array $consumiPerLotto, array $carichi, array $percorso): ?array
    {
        if (in_array($lotto, $percorso, true)) {
            return null; // ciclo non atteso: interrompe
        }
        $percorso[] = $lotto;

        $carico = $carichi[$lotto] ?? null;
        $consumi = $consumiPerLotto[$lotto] ?? [];

        $articolo = $carico?->articolo ?? '';
        if ($articolo === '' && $consumi !== []) {
            $articolo = $consumi[0]->articoloProdotto;
        }

        $componenti = [];
        foreach ($consumi as $c) {
            $figlio = null;
            if (isset($consumiPerLotto[$c->lotto])) {
                // Fattore del figlio = fattore corrente * (consumato / prodotto) di QUESTO consumo.
                $figlioFattore = $proporziona ? $fattore * $this->quota($c, $carichi) : 1.0;
                $figlio = $this->costruisciNodo($c->lotto, $figlioFattore, $proporziona, $consumiPerLotto, $carichi, $percorso);
            }

            $componenti[] = [
                'articolo' => $c->articolo,
                'lotto' => $c->lotto,
                // Quantita' riscalata per la quota usata lungo questo percorso (coerente con l'export Omni).
                'quantita' => $this->arrotonda((float) $c->quantita * $fattore),
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

    /** Arrotonda una quantita' ai decimali configurati; per eccesso (ceil) se richiesto. */
    private function arrotonda(float $v): float
    {
        $dec = max(0, $this->decimaliQuantita);
        if ($this->arrotondaEccesso) {
            $f = 10 ** $dec;

            return ceil($v * $f) / $f;
        }

        return round($v, $dec);
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
