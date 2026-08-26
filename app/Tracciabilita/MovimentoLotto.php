<?php

declare(strict_types=1);

namespace App\Tracciabilita;

/**
 * Riga di movimento di magazzino legata a un lotto (dal gestionale ESOLVER), §6-bis.
 * `tipo` = 'carico' (versamento da produzione) | 'scarico' (consumo per produzione).
 * Per uno scarico: `articolo`/`lotto` sono il COMPONENTE consumato, `lottoProdotto` e' il lotto del
 * prodotto per cui è stato consumato. Per un carico: `articolo`/`lotto` sono il prodotto versato.
 */
final class MovimentoLotto
{
    public function __construct(
        public readonly string $tipo,
        public readonly string $articolo,
        public readonly string $lotto,
        public readonly float $quantita,
        public readonly string $um,
        public readonly string $magazzino,
        public readonly ?string $data,
        public readonly string $causale,
        public readonly string $lottoProdotto = '',
        public readonly string $articoloProdotto = '',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo,
            'articolo' => $this->articolo,
            'lotto' => $this->lotto,
            'quantita' => $this->quantita,
            'um' => $this->um,
            'magazzino' => $this->magazzino,
            'data' => $this->data,
            'causale' => $this->causale,
            'lotto_prodotto' => $this->lottoProdotto,
            'articolo_prodotto' => $this->articoloProdotto,
        ];
    }
}
