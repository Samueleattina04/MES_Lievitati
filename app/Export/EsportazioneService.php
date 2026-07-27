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
     * Esporta un ordine COMPLETATO verso un gestionale e marca l'ordine "esportato" (senza impedire
     * l'export verso altri gestionali). Un solo tracciato -> contenuto in streaming (NESSUNA scrittura
     * su disco, quindi nessun problema di permessi); piu' tracciati -> ZIP in un file temporaneo di
     * sistema (poi eliminato dal chiamante).
     *
     * @return array{tipo:'contenuto', nome:string, mime:string, contenuto:string}
     *              |array{tipo:'zip', nome:string, mime:string, path:string}
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

        if (count($files) === 1) {
            // Un solo tracciato: contenuto restituito direttamente, scaricato in streaming.
            $file = $files[0];
            $risultato = [
                'tipo' => 'contenuto',
                'nome' => $file['nome'],
                'mime' => $file['mime'],
                'contenuto' => $file['contenuto'],
            ];
        } else {
            // Piu' tracciati: ZIP in un file temporaneo di sistema (dir sempre scrivibile).
            $path = tempnam(sys_get_temp_dir(), 'mesexport');
            if ($path === false) {
                throw new RuntimeException('Impossibile creare il file temporaneo di export.');
            }
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossibile creare lo ZIP di export.');
            }
            foreach ($files as $file) {
                $zip->addFromString($file['nome'], $file['contenuto']);
            }
            $zip->close();
            $risultato = [
                'tipo' => 'zip',
                'nome' => "export_{$gestionale}_{$ordine->numero}.zip",
                'mime' => 'application/zip',
                'path' => $path,
            ];
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
