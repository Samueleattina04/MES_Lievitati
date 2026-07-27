<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Export\EsportazioneService;
use App\Models\OrdineProduzione;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Esportazione dei tracciati per i gestionali (§10). Disponibile al backoffice di produzione:
 * genera i file per gli ordini con tutte le fasi chiuse e li marca "esportato".
 */
class EsportazioneController extends Controller
{
    public function __construct(
        private readonly EsportazioneService $service,
    ) {}

    public function esporta(Request $request, OrdineProduzione $ordine, string $gestionale): Response
    {
        try {
            $file = $this->service->esporta($ordine, $gestionale, $request->user()->id);

            // ZIP (piu' tracciati): file temporaneo eliminato dopo l'invio.
            if (($file['tipo'] ?? null) === 'zip') {
                return response()->download($file['path'], $file['nome'], ['Content-Type' => $file['mime']])
                    ->deleteFileAfterSend(true);
            }

            // File singolo: contenuto in streaming, nessuna scrittura su disco.
            return response($file['contenuto'], 200, [
                'Content-Type' => $file['mime'],
                'Content-Disposition' => 'attachment; filename="'.$file['nome'].'"',
            ]);
        } catch (Throwable $e) {
            // Qualsiasi errore (anche nella costruzione del download) diventa un messaggio leggibile
            // invece di un 500 opaco; l'eccezione completa resta nel log per la diagnosi.
            Log::error('Export fallito', [
                'ordine' => $ordine->numero,
                'gestionale' => $gestionale,
                'errore' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return back()->with('error', "Export {$gestionale} non riuscito: ".$e->getMessage());
        }
    }
}
