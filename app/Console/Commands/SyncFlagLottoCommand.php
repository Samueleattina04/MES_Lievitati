<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Bom\Contracts\BomSourceAdapterInterface;
use App\Enums\StatoFase;
use App\Enums\StatoOrdine;
use App\Models\Articolo;
use App\Models\MaterialeFase;
use App\Models\OrdineProduzione;
use Illuminate\Console\Command;

/**
 * Ri-sincronizza flag_lotto degli articoli dall'anagrafica del gestionale
 * (ArtAnagrafica.MagGiacPerLotti, <> 0 = gestito a lotti), senza ricreare gli ordini.
 *
 *  - di default aggiorna solo articoli.flag_lotto (impatta i NUOVI ordini);
 *  - con --ordini aggiorna anche le righe materiale (materiali_fase) degli ordini aperti/in
 *    lavorazione, sulle fasi non ancora chiuse, cosi' il flag vale anche sugli ordini gia' esplosi;
 *  - con --dry-run mostra le modifiche senza scriverle.
 *
 * L'override manuale (flag_lotto_override) resta prioritario: le righe materiale sono allineate a
 * Articolo::richiedeLotto(), esattamente come alla creazione dell'ordine.
 */
final class SyncFlagLottoCommand extends Command
{
    protected $signature = 'mes:sync-flag-lotto
        {--ordini : Aggiorna anche le righe materiale degli ordini aperti/in lavorazione (fasi non chiuse)}
        {--dry-run : Mostra le modifiche senza applicarle}';

    protected $description = "Sincronizza flag_lotto dall'anagrafica del gestionale (ArtAnagrafica.MagGiacPerLotti)";

    public function handle(BomSourceAdapterInterface $adapter): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $codici = Articolo::query()->pluck('codice')->all();
        if ($codici === []) {
            $this->warn('Nessun articolo in cache: crea prima almeno un ordine.');

            return self::SUCCESS;
        }

        $flag = $adapter->flagLottoPerArticoli($codici);
        if ($flag === []) {
            $this->warn("L'adapter distinte non ha restituito alcun flag. In sviluppo e' normale (il flag arriva dalla config MES, non dal gestionale); sul server verifica di essere su MES_BOM_ADAPTER=sqlsrv.");

            return self::SUCCESS;
        }

        $numFlag = count($flag);
        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Flag ricevuti dal gestionale: {$numFlag} articoli.");

        $modArticoli = $this->sincronizzaArticoli($flag, $dryRun);
        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Articoli con flag_lotto aggiornato: {$modArticoli}.");

        if ($this->option('ordini')) {
            $modMateriali = $this->sincronizzaMateriali($flag, $dryRun);
            $this->info(($dryRun ? '[DRY-RUN] ' : '')."Righe materiale (ordini aperti) aggiornate: {$modMateriali}.");
        } else {
            $this->line('Suggerimento: usa --ordini per allineare anche gli ordini gia\' aperti.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,bool>  $flag
     */
    private function sincronizzaArticoli(array $flag, bool $dryRun): int
    {
        $modificati = 0;

        foreach (Articolo::query()->get() as $articolo) {
            if (! array_key_exists($articolo->codice, $flag)) {
                continue;
            }
            $nuovo = $flag[$articolo->codice];
            if ((bool) $articolo->flag_lotto === $nuovo) {
                continue;
            }

            $this->line(sprintf(
                '  %s: flag_lotto %s -> %s',
                $articolo->codice,
                (bool) $articolo->flag_lotto ? 'true' : 'false',
                $nuovo ? 'true' : 'false',
            ));

            if (! $dryRun) {
                $articolo->flag_lotto = $nuovo;
                $articolo->save();
            }
            $modificati++;
        }

        return $modificati;
    }

    /**
     * @param  array<string,bool>  $flag  Flag "a lotti" dal gestionale (stato POST-sync degli articoli).
     */
    private function sincronizzaMateriali(array $flag, bool $dryRun): int
    {
        // richiedeLotto() POST-sync per codice: override manuale prioritario, altrimenti il flag dal
        // gestionale (o quello attuale se il codice non e' nella mappa). Cosi' il conteggio e' corretto
        // anche in dry-run, dove gli articoli non sono ancora stati salvati.
        $richiede = [];
        foreach (Articolo::with('configurazioneMes')->get() as $a) {
            $override = $a->configurazioneMes?->flag_lotto_override;
            $base = array_key_exists($a->codice, $flag) ? $flag[$a->codice] : (bool) $a->flag_lotto;
            $richiede[$a->codice] = $override !== null ? (bool) $override : $base;
        }

        $ordiniAperti = OrdineProduzione::query()
            ->whereIn('stato', [StatoOrdine::Aperto->value, StatoOrdine::InLavorazione->value])
            ->pluck('id')
            ->all();

        if ($ordiniAperti === []) {
            return 0;
        }

        $modificati = 0;

        MaterialeFase::query()
            ->whereHas('fase', function ($q) use ($ordiniAperti) {
                $q->whereIn('ordine_id', $ordiniAperti)
                    ->where('stato', '!=', StatoFase::Chiusa->value);
            })
            ->chunkById(500, function ($materiali) use (&$modificati, $richiede, $dryRun) {
                foreach ($materiali as $m) {
                    if (! array_key_exists($m->articolo_codice, $richiede)) {
                        continue;
                    }
                    $nuovo = $richiede[$m->articolo_codice];
                    if ((bool) $m->flag_lotto === $nuovo) {
                        continue;
                    }

                    if (! $dryRun) {
                        $m->flag_lotto = $nuovo;
                        $m->save();
                    }
                    $modificati++;
                }
            });

        return $modificati;
    }
}
