<?php

declare(strict_types=1);

namespace App\Bom;

/**
 * Una riga dell'esplosione di distinta: un arco padre->figlio (o la radice, con padre null).
 * Value object immutabile prodotto da BomSourceAdapterInterface e consumato dai servizi di dominio.
 *
 * `qtaPerUnita` e' la quantita' cumulata del componente per UNA unita' di prodotto finito,
 * gia' normalizzata dividendo per QtaRifDb lungo tutto il percorso (§4.3). Va moltiplicata
 * per la quantita' d'ordine per ottenere la quantita' pianificata.
 */
final readonly class BomRow
{
    public function __construct(
        public int $livello,
        public string $articolo,
        public ?string $articoloPadre,
        public ?string $descrizione,
        public ?string $udm,
        public float $qtaPerUnita,
        public bool $isProdotto,
        public int $posizione = 0,
    ) {}

    public function eRadice(): bool
    {
        return $this->articoloPadre === null;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            livello: (int) ($data['livello'] ?? 0),
            articolo: (string) $data['articolo'],
            articoloPadre: isset($data['articolo_padre']) ? (string) $data['articolo_padre'] : null,
            descrizione: isset($data['descrizione']) ? (string) $data['descrizione'] : null,
            udm: isset($data['udm']) ? (string) $data['udm'] : null,
            qtaPerUnita: (float) ($data['qta_per_unita'] ?? 0),
            isProdotto: (bool) ($data['is_prodotto'] ?? false),
            posizione: (int) ($data['posizione'] ?? 0),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'livello' => $this->livello,
            'articolo' => $this->articolo,
            'articolo_padre' => $this->articoloPadre,
            'descrizione' => $this->descrizione,
            'udm' => $this->udm,
            'qta_per_unita' => $this->qtaPerUnita,
            'is_prodotto' => $this->isProdotto,
            'posizione' => $this->posizione,
        ];
    }
}
