<?php

declare(strict_types=1);

namespace App\Ordini;

use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Enums\OrigineOrdine;
use App\Enums\StatoOrdine;
use App\Models\LogEvento;
use App\Models\OrdineProduzione;
use App\Support\LogEventi;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrazione della creazione di un ordine di produzione (§4.1, §14 Fase 2):
 * validazione articolo -> esplosione live dal gestionale -> piano -> materializzazione in MySQL.
 */
final class OrdineProduzioneService
{
    public function __construct(
        private readonly BomSourceAdapterInterface $adapter,
        private readonly OrderExplosionPlanner $planner,
        private readonly OrderMaterializer $materializer,
    ) {}

    /**
     * @param array{articolo_finito_codice:string, quantita:float|int|string, data?:string, numero?:string, note?:string, creato_da_id?:int} $dati
     */
    public function creaManuale(array $dati): OrdineProduzione
    {
        $codice = trim((string) $dati['articolo_finito_codice']);
        $quantita = (float) $dati['quantita'];

        if ($quantita <= 0) {
            throw new RuntimeException('La quantita di produzione deve essere maggiore di zero.');
        }

        if (! $this->adapter->esisteArticolo($codice)) {
            throw new RuntimeException("L'articolo '{$codice}' non esiste o non ha una distinta nel gestionale.");
        }

        $esplosione = $this->adapter->explode($codice);
        if ($esplosione->vuota()) {
            throw new RuntimeException("La distinta di '{$codice}' e' vuota: impossibile generare le fasi.");
        }

        $piano = $this->planner->plan($esplosione, $quantita);
        $radice = $esplosione->rigaRadice();

        // Flag "gestito a lotti" dall'anagrafica del gestionale, per popolare flag_lotto in
        // automatico su tutti gli articoli della distinta (§5.2). Lettura fuori transazione.
        $codiciArticoli = $esplosione->righe()->map(fn ($r) => $r->articolo)->unique()->values()->all();
        $flagLotto = $this->adapter->flagLottoPerArticoli($codiciArticoli);

        return DB::transaction(function () use ($dati, $codice, $quantita, $esplosione, $piano, $radice, $flagLotto) {
            $ordine = OrdineProduzione::create([
                'numero' => $dati['numero'] ?? $this->generaNumero(),
                'articolo_finito_codice' => $codice,
                'descrizione_articolo' => $radice?->descrizione,
                'quantita' => $quantita,
                'udm' => $radice?->udm,
                'data' => $dati['data'] ?? now()->toDateString(),
                'stato' => StatoOrdine::Aperto,
                'origine' => OrigineOrdine::Manuale,
                'creato_da_id' => $dati['creato_da_id'] ?? null,
                'note' => $dati['note'] ?? null,
            ]);

            $this->materializer->materializza($ordine, $esplosione, $piano, $flagLotto);

            LogEventi::registra('ordine_creato', $ordine, $dati['creato_da_id'] ?? null, [
                'articolo' => $codice,
                'quantita' => $quantita,
                'fasi_generate' => $piano->conta(),
            ]);

            return $ordine->fresh();
        });
    }

    /**
     * Numero progressivo giornaliero: OP-YYYYMMDD-NNN.
     */
    private function generaNumero(): string
    {
        $prefisso = 'OP-'.now()->format('Ymd').'-';
        $ultimo = OrdineProduzione::where('numero', 'like', $prefisso.'%')
            ->orderByDesc('numero')
            ->value('numero');

        $seq = $ultimo ? ((int) substr($ultimo, -3)) + 1 : 1;

        return $prefisso.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
