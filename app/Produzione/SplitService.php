<?php

declare(strict_types=1);

namespace App\Produzione;

use App\Enums\StatoFase;
use App\Models\FaseOrdine;
use App\Models\FaseSplit;
use App\Models\MaterialeFase;
use App\Models\User;
use App\Support\LogEventi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ripartizione (split) di un nodo condiviso prodotto una sola volta (§5-bis).
 * La quantita' reale prodotta dalla fase sorgente viene distribuita tra le fasi padre che la
 * consumano; la registrazione dello split sblocca l'avvio di quelle fasi (via FaseGate).
 * Scope: singolo ordine (config mes.split_scope, default v1).
 */
final class SplitService
{
    public function __construct(
        private readonly float $tolleranza = 0.01,
    ) {}

    /**
     * Fasi padre (destinazioni) che consumano il nodo condiviso, con la quota pianificata suggerita.
     *
     * @return Collection<int,array{fase:FaseOrdine, quota_suggerita:float}>
     */
    public function destinazioni(FaseOrdine $sorgente): Collection
    {
        return $sorgente->fasiPadre()->get()->map(function (FaseOrdine $padre) use ($sorgente) {
            $materiale = MaterialeFase::where('fase_ordine_id', $padre->id)
                ->where('articolo_codice', $sorgente->articolo_prodotto_codice)
                ->first();

            return [
                'fase' => $padre,
                'quota_suggerita' => (float) ($materiale?->quantita_pianificata ?? 0),
            ];
        });
    }

    public function quantitaDaRipartire(FaseOrdine $sorgente): float
    {
        return (float) ($sorgente->quantita_prodotta ?? $sorgente->quantita_pianificata);
    }

    /**
     * Registra lo split. $assegnazioni: [fase_destinazione_id => quantita].
     *
     * @param array<int,float> $assegnazioni
     */
    public function registra(FaseOrdine $sorgente, array $assegnazioni, User $operatore): void
    {
        if (! $sorgente->is_nodo_condiviso) {
            throw new WorkflowException('Questa fase non e un nodo condiviso: nessuna ripartizione richiesta.');
        }
        if ($sorgente->stato !== StatoFase::Chiusa) {
            throw new WorkflowException('La fase condivisa deve essere chiusa prima di poter ripartire.');
        }

        $destinazioniValide = $this->destinazioni($sorgente)->pluck('fase.id')->all();
        foreach (array_keys($assegnazioni) as $faseId) {
            if (! in_array($faseId, $destinazioniValide, true)) {
                throw new WorkflowException("La fase {$faseId} non consuma questo semilavorato.");
            }
        }

        $somma = array_sum(array_map('floatval', $assegnazioni));
        $daRipartire = $this->quantitaDaRipartire($sorgente);

        if (! Tolleranza::entro($daRipartire, $somma, $this->tolleranza)) {
            throw new WorkflowException(sprintf(
                'La somma delle quote (%s) deve coincidere con la quantita prodotta (%s), tolleranza +/-%s.',
                rtrim(rtrim(number_format($somma, 6, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($daRipartire, 6, '.', ''), '0'), '.'),
                $this->tolleranza,
            ));
        }

        DB::transaction(function () use ($sorgente, $assegnazioni, $operatore) {
            // Idempotenza: rigenera le righe di split per questa sorgente.
            FaseSplit::where('fase_sorgente_id', $sorgente->id)->delete();

            foreach ($assegnazioni as $faseDestId => $qta) {
                FaseSplit::create([
                    'fase_sorgente_id' => $sorgente->id,
                    'fase_destinazione_id' => $faseDestId,
                    'quantita_assegnata' => (float) $qta,
                    'operatore_id' => $operatore->id,
                ]);
            }

            $sorgente->update(['split_completato' => true]);

            LogEventi::registra('split_registrato', $sorgente, $operatore->id, [
                'assegnazioni' => $assegnazioni,
            ]);
        });
    }
}
