<?php

declare(strict_types=1);

namespace App\Produzione;

use App\Enums\StatoFase;

/**
 * Regole PURE di avanzamento delle fasi (§3, §5-bis, §8). Nessun accesso al DB: interamente
 * unit-testabile. Le decisioni operano su stati grezzi forniti dai servizi di persistenza.
 */
final class FaseGate
{
    /**
     * Precedenze bottom-up soddisfatte: tutte le fasi figlie (componenti prodotti) sono chiuse (§3).
     *
     * @param list<StatoFase> $statiFasiFiglie
     */
    public static function precedenzeSoddisfatte(array $statiFasiFiglie): bool
    {
        foreach ($statiFasiFiglie as $stato) {
            if ($stato !== StatoFase::Chiusa) {
                return false;
            }
        }

        return true;
    }

    /**
     * Uno step di fase e' avviabile adesso?
     *  - non deve essere gia' chiuso;
     *  - tutti gli step precedenti (ordine inferiore) devono essere chiusi;
     *  - se e' il PRIMO step, la fase deve avere precedenze soddisfatte e (se alimentata da un
     *    nodo condiviso) lo split registrato (§5-bis, §8).
     *
     * @param list<StatoFase> $statiStepPrecedenti stati degli step con ordine inferiore
     */
    public static function stepAvviabile(
        bool $precedenzeSoddisfatte,
        bool $splitMancante,
        array $statiStepPrecedenti,
        StatoFase $statoStep,
    ): bool {
        if ($statoStep === StatoFase::Chiusa) {
            return false;
        }

        foreach ($statiStepPrecedenti as $stato) {
            if ($stato !== StatoFase::Chiusa) {
                return false;
            }
        }

        // Primo step della fase: serve il via libera dalle precedenze e dall'eventuale split.
        if ($statiStepPrecedenti === []) {
            return $precedenzeSoddisfatte && ! $splitMancante;
        }

        return true;
    }

    /**
     * Motivo per cui uno step NON e' avviabile (per la UI "in attesa di: ...", §8). null = avviabile.
     *
     * @param list<StatoFase> $statiStepPrecedenti
     */
    public static function motivoBlocco(
        bool $precedenzeSoddisfatte,
        bool $splitMancante,
        array $statiStepPrecedenti,
        StatoFase $statoStep,
    ): ?string {
        if ($statoStep === StatoFase::Chiusa) {
            return 'Step gia\' chiuso.';
        }
        foreach ($statiStepPrecedenti as $stato) {
            if ($stato !== StatoFase::Chiusa) {
                return 'In attesa del completamento dello step precedente.';
            }
        }
        if ($statiStepPrecedenti === []) {
            if (! $precedenzeSoddisfatte) {
                return 'In attesa della chiusura delle fasi componenti.';
            }
            if ($splitMancante) {
                return 'In attesa della ripartizione (split) del semilavorato condiviso.';
            }
        }

        return null;
    }

    /**
     * La fase e' completamente chiusa quando tutti i suoi step sono chiusi (criterio 4).
     *
     * @param list<StatoFase> $statiStep
     */
    public static function tuttiStepChiusi(array $statiStep): bool
    {
        if ($statiStep === []) {
            return false;
        }
        foreach ($statiStep as $stato) {
            if ($stato !== StatoFase::Chiusa) {
                return false;
            }
        }

        return true;
    }
}
