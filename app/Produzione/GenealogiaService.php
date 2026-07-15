<?php

declare(strict_types=1);

namespace App\Produzione;

use App\Models\ConsumoMaterialeLotto;
use App\Models\FaseOrdine;
use App\Models\LottoProdotto;
use App\Models\MaterialeFase;

/**
 * Genealogia dei lotti (§6): legame fra i lotti delle materie prime consumate e i lotti dei
 * prodotti/semilavorati in uscita, per richiami e audit. La genealogia si propaga correttamente
 * attraverso i nodi condivisi/split: un lotto di impasto base diviso tra due prodotti risulta
 * collegato a entrambi, sia a ritroso sia in avanti.
 */
final class GenealogiaService
{
    /**
     * A RITROSO: dato il lotto di un prodotto/semilavorato, ricostruisce l'albero dei lotti consumati.
     *
     * @return list<array<string,mixed>>
     */
    public function aRitroso(string $lotto): array
    {
        return LottoProdotto::where('lotto', $lotto)->with('fase')->get()
            ->map(fn (LottoProdotto $lp) => $this->ritrosoFase($lp->fase, $lp->lotto))
            ->all();
    }

    /**
     * IN AVANTI: dato un lotto (materia prima o semilavorato), ricostruisce dove e' finito,
     * fino ai prodotti finiti.
     *
     * @return list<array<string,mixed>>
     */
    public function inAvanti(string $lotto): array
    {
        return $this->avantiDaLotto($lotto);
    }

    /** @return array<string,mixed> */
    private function ritrosoFase(FaseOrdine $fase, ?string $lotto): array
    {
        $fase->loadMissing('materiali');
        $consumi = [];

        // Fase soddisfatta da stock (§5.3): il semilavorato e' stato prelevato da un lotto esistente,
        // quindi in quest'ordine NON ha consumato i propri componenti. E' una foglia della genealogia.
        if (! $fase->completata_da_stock) {
            foreach ($fase->materiali as $materiale) {
                if ($materiale->e_semilavorato) {
                    $consumi = array_merge($consumi, $this->ritrosoSemilavorato($materiale));

                    continue;
                }

                // Materia prima: usa i lotti effettivamente consumati (multi-lotto).
                $consumo = $materiale->consumo()->with('lotti')->first();
                if ($consumo !== null && $consumo->lotti->isNotEmpty()) {
                    foreach ($consumo->lotti as $riga) {
                        $consumi[] = [
                            'tipo' => 'materia_prima',
                            'articolo' => $materiale->articolo_codice,
                            'lotto' => $riga->lotto,
                            'quantita' => (float) $riga->quantita,
                        ];
                    }
                } else {
                    $consumi[] = [
                        'tipo' => 'materia_prima',
                        'articolo' => $materiale->articolo_codice,
                        'lotto' => null,
                        'quantita' => $consumo !== null ? (float) $consumo->quantita_effettiva : (float) $materiale->quantita_pianificata,
                    ];
                }
            }
        }

        return [
            'tipo' => 'prodotto',
            'articolo' => $fase->articolo_prodotto_codice,
            'lotto' => $lotto,
            'fase_id' => $fase->id,
            'da_stock' => (bool) $fase->completata_da_stock,
            'quantita_prodotta' => (float) ($fase->quantita_prodotta ?? $fase->quantita_pianificata),
            'consumi' => $consumi,
        ];
    }

    /**
     * A ritroso di un componente semilavorato (§5.3): usa i lotti effettivamente consumati sulla
     * riga (propagati dalla fase produttrice, modificati a mano o presi da stock). Per ogni lotto
     * risale alla fase che lo ha prodotto (in quest'ordine o storicamente). In assenza di consumo
     * esplicito ripiega sulla fase produttrice di quest'ordine.
     *
     * @return list<array<string,mixed>>
     */
    private function ritrosoSemilavorato(MaterialeFase $materiale): array
    {
        $consumo = $materiale->consumo()->with('lotti')->first();

        if ($consumo !== null && $consumo->lotti->isNotEmpty()) {
            $out = [];
            foreach ($consumo->lotti as $riga) {
                $produttrice = LottoProdotto::where('lotto', $riga->lotto)
                    ->where('articolo_codice', $materiale->articolo_codice)
                    ->with('fase')
                    ->first()?->fase;

                // Se il lotto consumato non corrisponde a un lotto prodotto noto (es. lotto
                // digitato a mano diverso dal lotto in uscita), risali comunque alla fase
                // produttrice di quest'ordine tramite il legame strutturale.
                if ($produttrice === null && $materiale->fase_produttrice_id !== null) {
                    $produttrice = FaseOrdine::find($materiale->fase_produttrice_id);
                }

                $out[] = [
                    'tipo' => 'semilavorato',
                    'articolo' => $materiale->articolo_codice,
                    'lotto' => $riga->lotto,
                    'quantita' => (float) $riga->quantita,
                    'origine' => $produttrice !== null ? $this->ritrosoFase($produttrice, $riga->lotto) : null,
                ];
            }

            return $out;
        }

        // Nessun consumo esplicito registrato: ripiega sulla fase produttrice di quest'ordine.
        if ($materiale->fase_produttrice_id === null) {
            return [];
        }
        $produttrice = FaseOrdine::with('lottiProdotto')->find($materiale->fase_produttrice_id);
        if ($produttrice === null) {
            return [];
        }
        if ($produttrice->lottiProdotto->isEmpty()) {
            return [[
                'tipo' => 'semilavorato',
                'articolo' => $materiale->articolo_codice,
                'lotto' => null,
                'quantita' => (float) $materiale->quantita_pianificata,
                'origine' => $this->ritrosoFase($produttrice, null),
            ]];
        }

        $out = [];
        foreach ($produttrice->lottiProdotto as $lp) {
            $out[] = [
                'tipo' => 'semilavorato',
                'articolo' => $materiale->articolo_codice,
                'lotto' => $lp->lotto,
                'quantita' => (float) $materiale->quantita_pianificata,
                'origine' => $this->ritrosoFase($produttrice, $lp->lotto),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function avantiDaLotto(string $lotto): array
    {
        $utilizzi = [];

        // (a) Lotto di materia prima consumato direttamente in una fase.
        $righeLotto = ConsumoMaterialeLotto::where('lotto', $lotto)
            ->with('consumo.materiale.fase')
            ->get();
        foreach ($righeLotto as $riga) {
            $fase = $riga->consumo?->materiale?->fase;
            if ($fase !== null) {
                $utilizzi[] = $this->avantiFase($fase, (float) $riga->quantita, $riga->consumo->materiale->articolo_codice);
            }
        }

        // (b) Lotto di un semilavorato: consumato dalle fasi padre (anche piu' di una, via split),
        // via legame strutturale fase_produttrice. Le righe con consumo esplicito del lotto sono
        // gia' emerse dal ramo (a): qui si evita il doppio conteggio.
        $lottiProdotto = LottoProdotto::where('lotto', $lotto)->get();
        foreach ($lottiProdotto as $lp) {
            $materialiPadre = MaterialeFase::where('fase_produttrice_id', $lp->fase_ordine_id)
                ->where('e_semilavorato', true)
                ->with(['fase', 'consumo.lotti'])
                ->get();
            foreach ($materialiPadre as $mp) {
                if ($mp->fase === null) {
                    continue;
                }
                $giaTracciato = $mp->consumo !== null
                    && $mp->consumo->lotti->contains(fn ($l) => $l->lotto === $lotto);
                if ($giaTracciato) {
                    continue;
                }
                $utilizzi[] = $this->avantiFase($mp->fase, (float) $mp->quantita_pianificata, $mp->articolo_codice);
            }
        }

        return $utilizzi;
    }

    /** @return array<string,mixed> */
    private function avantiFase(FaseOrdine $fase, float $quantita, string $articoloConsumato): array
    {
        $fase->loadMissing('lottiProdotto');

        $prodotti = [];
        foreach ($fase->lottiProdotto as $lp) {
            $prodotti[] = [
                'lotto' => $lp->lotto,
                'usato_in' => $this->avantiDaLotto($lp->lotto),
            ];
        }

        return [
            'articolo' => $fase->articolo_prodotto_codice,
            'fase_id' => $fase->id,
            'consumato_come' => $articoloConsumato,
            'quantita' => $quantita,
            'prodotti' => $prodotti,
        ];
    }
}
