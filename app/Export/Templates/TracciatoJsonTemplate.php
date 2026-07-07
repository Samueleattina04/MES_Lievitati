<?php

declare(strict_types=1);

namespace App\Export\Templates;

use App\Export\Contracts\ExportTemplateInterface;
use App\Models\FaseOrdine;
use App\Models\OrdineProduzione;
use App\Produzione\GenealogiaService;

/**
 * Tracciato completo JSON (§10): consumi+lotti, versamenti, genealogia e consuntivazione tempi.
 * Placeholder ricco: quando il committente fornira' i tracciati reali, si aggiunge un template
 * dedicato senza toccare il core (§10).
 */
final class TracciatoJsonTemplate implements ExportTemplateInterface
{
    public function __construct(
        private readonly GenealogiaService $genealogia,
    ) {}

    public function chiave(): string
    {
        return 'tracciato_json';
    }

    public function etichetta(): string
    {
        return 'Tracciato completo (consumi, versamenti, genealogia, tempi) - JSON';
    }

    public function nomeFile(OrdineProduzione $ordine): string
    {
        return "tracciato_{$ordine->numero}.json";
    }

    public function mime(): string
    {
        return 'application/json';
    }

    public function contenuto(OrdineProduzione $ordine): string
    {
        $ordine->loadMissing(['fasi.materiali.consumo.lotti', 'fasi.lottiProdotto', 'fasi.steps.reparto', 'fasi.splitInUscita']);

        $dati = [
            'ordine' => [
                'numero' => $ordine->numero,
                'articolo' => $ordine->articolo_finito_codice,
                'descrizione' => $ordine->descrizione_articolo,
                'quantita' => (float) $ordine->quantita,
                'udm' => $ordine->udm,
                'data' => $ordine->data?->toDateString(),
                'stato' => $ordine->stato->value,
            ],
            'fasi' => $ordine->fasi->map(fn (FaseOrdine $f) => $this->fase($f))->all(),
        ];

        return (string) json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private function fase(FaseOrdine $f): array
    {
        return [
            'articolo' => $f->articolo_prodotto_codice,
            'descrizione' => $f->descrizione,
            'quantita_pianificata' => (float) $f->quantita_pianificata,
            'quantita_prodotta' => $f->quantita_prodotta !== null ? (float) $f->quantita_prodotta : null,
            'udm' => $f->udm,
            'is_nodo_condiviso' => $f->is_nodo_condiviso,
            'inizio' => $f->timestamp_inizio?->toIso8601String(),
            'fine' => $f->timestamp_fine?->toIso8601String(),
            'durata_minuti' => $this->durata($f->timestamp_inizio, $f->timestamp_fine),
            'step' => $f->steps->map(fn ($s) => [
                'reparto' => $s->reparto?->descrizione,
                'stato' => $s->stato->value,
                'inizio' => $s->timestamp_inizio?->toIso8601String(),
                'fine' => $s->timestamp_fine?->toIso8601String(),
                'durata_minuti' => $this->durata($s->timestamp_inizio, $s->timestamp_fine),
            ])->all(),
            'materiali' => $f->materiali->map(fn ($m) => [
                'articolo' => $m->articolo_codice,
                'quantita_pianificata' => (float) $m->quantita_pianificata,
                'quantita_effettiva' => $m->consumo?->quantita_effettiva !== null ? (float) $m->consumo->quantita_effettiva : null,
                'lotti' => $m->consumo ? $m->consumo->lotti->map(fn ($l) => ['lotto' => $l->lotto, 'quantita' => (float) $l->quantita])->all() : [],
            ])->all(),
            'lotti_prodotto' => $f->lottiProdotto->map(fn ($l) => ['lotto' => $l->lotto, 'quantita' => $l->quantita !== null ? (float) $l->quantita : null])->all(),
            'split' => $f->splitInUscita->map(fn ($s) => ['fase_destinazione_id' => $s->fase_destinazione_id, 'quantita' => (float) $s->quantita_assegnata])->all(),
            'genealogia_a_ritroso' => $f->lottiProdotto->flatMap(fn ($l) => $this->genealogia->aRitroso($l->lotto))->all(),
        ];
    }

    private function durata(?\DateTimeInterface $inizio, ?\DateTimeInterface $fine): ?int
    {
        if ($inizio === null || $fine === null) {
            return null;
        }

        return (int) round(($fine->getTimestamp() - $inizio->getTimestamp()) / 60);
    }
}
