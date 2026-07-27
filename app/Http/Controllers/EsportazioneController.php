<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Export\EsportazioneService;
use App\Models\OrdineProduzione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    public function esporta(Request $request, OrdineProduzione $ordine, string $gestionale): BinaryFileResponse|RedirectResponse
    {
        try {
            $file = $this->service->esporta($ordine, $gestionale, $request->user()->id);
        } catch (Throwable $e) {
            // Qualsiasi errore (non solo di validazione) diventa un messaggio leggibile invece di un 500
            // opaco; l'eccezione completa resta nel log per la diagnosi.
            Log::error('Export fallito', [
                'ordine' => $ordine->numero,
                'gestionale' => $gestionale,
                'errore' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return back()->with('error', "Export {$gestionale} non riuscito: ".$e->getMessage());
        }

        return response()->download($file['path'], $file['nome'], ['Content-Type' => $file['mime']])
            ->deleteFileAfterSend(true);
    }
}
