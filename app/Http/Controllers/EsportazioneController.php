<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Export\EsportazioneService;
use App\Models\OrdineProduzione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Esportazione dei tracciati per i gestionali (§10). Disponibile al backoffice di produzione:
 * genera i file (ZIP) per gli ordini con tutte le fasi chiuse e li marca "esportato".
 */
class EsportazioneController extends Controller
{
    public function __construct(
        private readonly EsportazioneService $service,
    ) {}

    public function esporta(Request $request, OrdineProduzione $ordine): BinaryFileResponse|RedirectResponse
    {
        try {
            $path = $this->service->esportaZip($ordine, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->download($path)->deleteFileAfterSend(true);
    }
}
