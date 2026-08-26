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
    ) {}

    /**
     * @return array{lotto:string, trovato:bool, nodo:?array<string,mixed>, movimenti:list<array<string,mixed>>}
     */
    public function albero(string $lottoRadice): array
    {
        $lottoRadice = trim($lottoRadice);
        if ($lottoRadice === '') {
            return ['lotto' => '', 'trovato' => false, 'nodo' => null, 'movimenti' => []];
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
                if ($c->lotto !== '' && ! isset($visitati[$c->lotto])) {
                    $prossima[$c->lotto] = true;
                }
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

        $nodo = $this->costruisciNodo($lottoRadice, $consumiPerLotto, $carichi, []);
        $trovato = isset($carichi[$lottoRadice]) || ! empty($consumiPerLotto[$lottoRadice]);

        return [
            'lotto' => $lottoRadice,
            'trovato' => $trovato,
            'nodo' => $nodo,
            'movimenti' => $this->flatten($consumiPerLotto, $carichi),
        ];
    }

    /**
     * @param  array<string,list<MovimentoLotto>>  $consumiPerLotto
     * @param  array<string,MovimentoLotto>  $carichi
     * @param  list<string>  $percorso
     * @return array<string,mixed>|null
     */
    private function costruisciNodo(string $lotto, array $consumiPerLotto, array $carichi, array $percorso): ?array
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
            $figlio = isset($consumiPerLotto[$c->lotto])
                ? $this->costruisciNodo($c->lotto, $consumiPerLotto, $carichi, $percorso)
                : null;

            $componenti[] = [
                'articolo' => $c->articolo,
                'lotto' => $c->lotto,
                'quantita' => $c->quantita,
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

        return $out;
    }
}
