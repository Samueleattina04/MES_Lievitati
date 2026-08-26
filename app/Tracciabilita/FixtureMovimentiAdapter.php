<?php

declare(strict_types=1);

namespace App\Tracciabilita;

use App\Tracciabilita\Contracts\MovimentiLottoSourceInterface;

/**
 * Adapter movimenti per sviluppo/test: legge una lista di movimenti in memoria. In locale non c'e' il
 * gestionale, quindi di default e' vuoto; i test iniettano i movimenti. Ogni riga:
 *   ['tipo'=>'carico'|'scarico','articolo','lotto','quantita','um','magazzino','data','causale',
 *    'lotto_prodotto'?, 'articolo_prodotto'?]
 */
final class FixtureMovimentiAdapter implements MovimentiLottoSourceInterface
{
    /** @var list<MovimentoLotto> */
    private array $movimenti;

    /** @param list<array<string,mixed>> $movimenti */
    public function __construct(array $movimenti = [])
    {
        $this->movimenti = array_map(static fn (array $m) => new MovimentoLotto(
            tipo: (string) ($m['tipo'] ?? 'scarico'),
            articolo: (string) ($m['articolo'] ?? ''),
            lotto: trim((string) ($m['lotto'] ?? '')),
            quantita: (float) ($m['quantita'] ?? 0),
            um: (string) ($m['um'] ?? ''),
            magazzino: (string) ($m['magazzino'] ?? ''),
            data: isset($m['data']) ? (string) $m['data'] : null,
            causale: (string) ($m['causale'] ?? ''),
            lottoProdotto: trim((string) ($m['lotto_prodotto'] ?? '')),
            articoloProdotto: (string) ($m['articolo_prodotto'] ?? ''),
        ), $movimenti);
    }

    public function consumiPerProdotti(array $lottiProdotto): array
    {
        $set = array_flip(array_map(static fn ($l) => trim((string) $l), $lottiProdotto));

        return array_values(array_filter(
            $this->movimenti,
            static fn (MovimentoLotto $m) => $m->tipo === 'scarico' && isset($set[$m->lottoProdotto]),
        ));
    }

    public function carichiPerLotti(array $lotti): array
    {
        $set = array_flip(array_map(static fn ($l) => trim((string) $l), $lotti));

        return array_values(array_filter(
            $this->movimenti,
            static fn (MovimentoLotto $m) => $m->tipo === 'carico' && isset($set[$m->lotto]),
        ));
    }
}
