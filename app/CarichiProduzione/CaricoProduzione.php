<?php

declare(strict_types=1);

namespace App\CarichiProduzione;

/**
 * Riga di un CARICO DI PRODUZIONE da prima nota (§ Carichi): il versamento a magazzino di un
 * articolo prodotto, con i dati del documento (operatore creatore) e la classificazione
 * merceologica (famiglia / macrofamiglia / microfamiglia). DTO puro, nessuna dipendenza dal DB.
 */
final class CaricoProduzione
{
    public function __construct(
        public readonly ?string $data,
        public readonly string $articolo,
        public readonly string $descrizione,
        public readonly string $lotto,
        public readonly float $quantita,
        public readonly string $um,
        public readonly string $magazzino,
        public readonly string $lottoPf,
        public readonly string $causale,
        public readonly string $codOpMag,
        public readonly string $operatore,
        public readonly string $famiglia = '',
        public readonly string $macrofamiglia = '',
        public readonly string $microfamiglia = '',
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'articolo' => $this->articolo,
            'descrizione' => $this->descrizione,
            'lotto' => $this->lotto,
            'quantita' => $this->quantita,
            'um' => $this->um,
            'magazzino' => $this->magazzino,
            'lotto_pf' => $this->lottoPf,
            'causale' => $this->causale,
            'cod_op_mag' => $this->codOpMag,
            'operatore' => $this->operatore,
            'famiglia' => $this->famiglia,
            'macrofamiglia' => $this->macrofamiglia,
            'microfamiglia' => $this->microfamiglia,
        ];
    }
}
