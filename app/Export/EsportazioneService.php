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
 * Genera i tracciati di export per gli ordini completati (§10). I template sono raggruppati per
 * GESTIONALE di destinazione (es. 'esolver', 'omni'), così la UI puo' offrire un export dedicato per
 * ciascuno. Esportare verso un gestionale NON impedisce di esportare verso l'altro: l'ordine viene
 * marcato "esportato" (non piu' modificabile) ma resta ri-esportabile/scaricabile.
 */
final class EsportazioneService
{
    /** @param array<string, list<ExportTemplateInterface>> $templatesPerGestionale */
    public function __construct(
        private readonly array $templatesPerGestionale,
    ) {}

    /**
     * Gestionali con almeno un tracciato configurato (per abilitare i bottoni nella UI).
     *
     * @return list<string>
     */
    public function gestionaliConfigurati(): array
    {
        return array_values(array_keys(array_filter(
            $this->templatesPerGestionale,
            static fn (array $t) => $t !== [],
        )));
    }

    /**
     * Genera i file di un gestionale (senza marcare l'ordine).
     *
     * @return list<array{nome:string, mime:string, contenuto:string}>
     */
    public function genera(OrdineProduzione $ordine, string $gestionale): array
    {
        return array_map(fn (ExportTemplateInterface $t) => [
            'nome' => $t->nomeFile($ordine),
            'mime' => $t->mime(),
            'contenuto' => $t->contenuto($ordine),
        ], $this->templatesPerGestionale[$gestionale] ?? []);
    }

    /**
     * Esporta un ordine COMPLETATO verso un gestionale: scrive il file (o uno ZIP se piu' tracciati)
     * e marca l'ordine "esportato" (senza impedire l'export verso altri gestionali). Restituisce il
     * percorso e i metadati del file da scaricare (il chiamante lo invia e lo elimina).
     *
     * @return array{path:string, nome:string, mime:string}
     */
    public function esporta(OrdineProduzione $ordine, string $gestionale, ?int $userId = null): array
    {
        // Ammessi Completato ed Esportato: cosi' si puo' esportare per piu' gestionali (o ri-scaricare).
        if (! in_array($ordine->stato, [StatoOrdine::Completato, StatoOrdine::Esportato], true)) {
            throw new RuntimeException('Solo gli ordini con tutte le fasi chiuse (completati) possono essere esportati.');
        }

        $files = $this->genera($ordine, $gestionale);
        if ($files === []) {
            throw new RuntimeException("Nessun tracciato configurato per il gestionale '{$gestionale}'.");
        }

        $dir = storage_path('app/export');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (count($files) === 1) {
            // Un solo tracciato: si scarica direttamente il file (niente ZIP).
            $file = $files[0];
            $path = $dir.DIRECTORY_SEPARATOR.$file['nome'];
            file_put_contents($path, $file['contenuto']);
            $risultato = ['path' => $path, 'nome' => $file['nome'], 'mime' => $file['mime']];
        } else {
            $nomeZip = "export_{$gestionale}_{$ordine->numero}.zip";
            $path = $dir.DIRECTORY_SEPARATOR.$nomeZip;
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossibile creare il file di export.');
            }
            foreach ($files as $file) {
                $zip->addFromString($file['nome'], $file['contenuto']);
            }
            $zip->close();
            $risultato = ['path' => $path, 'nome' => $nomeZip, 'mime' => 'application/zip'];
        }

        if ($ordine->stato !== StatoOrdine::Esportato) {
            $ordine->update(['stato' => StatoOrdine::Esportato, 'esportato_at' => now()]);
        }
        LogEventi::registra('ordine_esportato', $ordine, $userId, [
            'numero' => $ordine->numero,
            'gestionale' => $gestionale,
        ]);

        return $risultato;
    }
}
