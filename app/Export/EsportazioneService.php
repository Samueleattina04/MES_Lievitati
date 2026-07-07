<?php

declare(strict_types=1);

namespace App\Export;

use App\Enums\StatoOrdine;
use App\Export\Contracts\ExportTemplateInterface;
use App\Models\OrdineProduzione;
use App\Support\LogEventi;
use RuntimeException;
use ZipArchive;

/**
 * Genera i tracciati di export per gli ordini completati (§10) usando i template registrati,
 * li impacchetta in uno ZIP e marca l'ordine come "esportato" (non piu' modificabile).
 */
final class EsportazioneService
{
    /** @param list<ExportTemplateInterface> $templates */
    public function __construct(
        private readonly array $templates,
    ) {}

    /** @return list<ExportTemplateInterface> */
    public function templates(): array
    {
        return $this->templates;
    }

    /**
     * Genera i file (senza marcare l'ordine).
     *
     * @return list<array{nome:string, mime:string, contenuto:string}>
     */
    public function genera(OrdineProduzione $ordine): array
    {
        return array_map(fn (ExportTemplateInterface $t) => [
            'nome' => $t->nomeFile($ordine),
            'mime' => $t->mime(),
            'contenuto' => $t->contenuto($ordine),
        ], $this->templates);
    }

    /**
     * Esporta un ordine COMPLETATO: crea uno ZIP con tutti i tracciati, marca "esportato".
     * Restituisce il percorso del file ZIP temporaneo (il chiamante lo scarica e lo elimina).
     */
    public function esportaZip(OrdineProduzione $ordine, ?int $userId = null): string
    {
        if ($ordine->stato === StatoOrdine::Esportato) {
            throw new RuntimeException("L'ordine {$ordine->numero} e' gia' stato esportato.");
        }
        if ($ordine->stato !== StatoOrdine::Completato) {
            throw new RuntimeException("Solo gli ordini con tutte le fasi chiuse (completati) possono essere esportati.");
        }

        $dir = storage_path('app/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $zipPath = $dir.DIRECTORY_SEPARATOR."export_{$ordine->numero}.zip";

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file di export.');
        }
        foreach ($this->genera($ordine) as $file) {
            $zip->addFromString($file['nome'], $file['contenuto']);
        }
        $zip->close();

        $ordine->update(['stato' => StatoOrdine::Esportato, 'esportato_at' => now()]);
        LogEventi::registra('ordine_esportato', $ordine, $userId, ['numero' => $ordine->numero]);

        return $zipPath;
    }
}
