<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operatore;

use App\Http\Controllers\Controller;
use App\Models\FaseOrdine;
use App\Models\FaseOrdineStep;
use App\Models\MaterialeFase;
use App\Models\SyncQueue;
use App\Produzione\FaseWorkflowService;
use App\Produzione\SplitService;
use App\Produzione\WorkflowException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Endpoint di sincronizzazione della coda offline (§8). Riceve un batch di azioni eseguite
 * dall'operatore (anche mentre era offline) e le applica in ordine, in modo IDEMPOTENTE:
 * un client_uuid gia' processato viene ignorato silenziosamente (retry di rete).
 *
 * Lo stesso endpoint serve sia il flusso online sia il flush della coda IndexedDB, cosi' il
 * percorso d'azione lato client e' unico.
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly FaseWorkflowService $workflow,
        private readonly SplitService $splitService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'azioni' => ['required', 'array', 'min:1'],
            'azioni.*.client_uuid' => ['required', 'uuid'],
            'azioni.*.tipo_azione' => ['required', 'string'],
            'azioni.*.payload' => ['required', 'array'],
        ]);

        $operatore = $request->user();
        $risultati = [];

        foreach ($dati['azioni'] as $azione) {
            $risultati[] = $this->processaAzione($azione, $operatore);
        }

        return response()->json(['risultati' => $risultati]);
    }

    /**
     * @param array{client_uuid:string, tipo_azione:string, payload:array<string,mixed>} $azione
     * @return array{client_uuid:string, ok:bool, errore?:string, duplicato?:bool}
     */
    private function processaAzione(array $azione, $operatore): array
    {
        $uuid = $azione['client_uuid'];

        // Idempotenza: se gia' processata, non rieseguire.
        $esistente = SyncQueue::where('client_uuid', $uuid)->first();
        if ($esistente !== null && $esistente->processato) {
            return [
                'client_uuid' => $uuid,
                'ok' => $esistente->errore === null,
                'duplicato' => true,
                'errore' => $esistente->errore,
            ];
        }

        $coda = $esistente ?? SyncQueue::create([
            'client_uuid' => $uuid,
            'tipo_azione' => $azione['tipo_azione'],
            'payload' => $azione['payload'],
            'processato' => false,
        ]);

        try {
            $this->esegui($azione['tipo_azione'], $azione['payload'], $operatore, $uuid);
            $coda->update(['processato' => true, 'processato_at' => now(), 'errore' => null]);

            return ['client_uuid' => $uuid, 'ok' => true];
        } catch (WorkflowException $e) {
            // Rifiuto di dominio: non ha senso riprovare (permanente). Segna processata con errore.
            $coda->update(['processato' => true, 'processato_at' => now(), 'errore' => $e->getMessage()]);

            return ['client_uuid' => $uuid, 'ok' => false, 'permanente' => true, 'errore' => $e->getMessage()];
        } catch (Throwable $e) {
            // Errore tecnico: lascia processato=false per consentire un nuovo tentativo (transitorio).
            report($e);

            return ['client_uuid' => $uuid, 'ok' => false, 'permanente' => false, 'errore' => 'Errore temporaneo, verra riprovato.'];
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function esegui(string $tipo, array $payload, $operatore, string $clientUuid): void
    {
        match ($tipo) {
            'avvio_step' => $this->workflow->avvia(
                FaseOrdineStep::findOrFail($payload['step_id']),
                $operatore,
            ),
            'conferma_materiale' => $this->workflow->confermaMateriale(
                MaterialeFase::findOrFail($payload['materiale_id']),
                (float) $payload['quantita_effettiva'],
                $operatore,
                $payload['lotti'] ?? [],
                $clientUuid,
                (bool) ($payload['conferma_superamento'] ?? false),
            ),
            'chiusura_step' => $this->workflow->chiudiStep(
                FaseOrdineStep::findOrFail($payload['step_id']),
                $operatore,
                isset($payload['quantita_prodotta']) ? (float) $payload['quantita_prodotta'] : null,
                $payload['lotto_prodotto'] ?? null,
            ),
            // Prelievo da stock (§5.3, change #3): chiude la fase con un lotto esistente, senza consumo.
            'completa_da_stock' => $this->workflow->completaDaStock(
                FaseOrdine::findOrFail($payload['fase_id']),
                (string) ($payload['lotto'] ?? ''),
                $operatore,
                $clientUuid,
            ),
            'split' => $this->splitService->registra(
                FaseOrdine::findOrFail($payload['fase_id']),
                $this->normalizzaAssegnazioni($payload['assegnazioni'] ?? []),
                $operatore,
            ),
            default => throw new WorkflowException("Tipo azione sconosciuto: {$tipo}."),
        };
    }

    /**
     * @param array<int|string,mixed> $assegnazioni
     * @return array<int,float>
     */
    private function normalizzaAssegnazioni(array $assegnazioni): array
    {
        $out = [];
        foreach ($assegnazioni as $chiave => $valore) {
            // Accetta sia { fase_id: qta } sia [ {fase_destinazione_id, quantita} ].
            if (is_array($valore)) {
                $out[(int) $valore['fase_destinazione_id']] = (float) $valore['quantita'];
            } else {
                $out[(int) $chiave] = (float) $valore;
            }
        }

        return $out;
    }
}
