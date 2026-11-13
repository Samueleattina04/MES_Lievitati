<?php

declare(strict_types=1);

namespace App\CarichiProduzione;

/**
 * Filtro per classificazione merceologica (§ Carichi): dai carichi gia' letti ricava le opzioni
 * disponibili (valori distinti di famiglia/macro/microfamiglia) e applica la selezione a checklist.
 * Logica pura, nessun DB: il filtro gira in PHP sui carichi del periodo, cosi' tabella ed Excel
 * restano coerenti. Per ogni gruppo: selezione vuota = nessun filtro (mostra tutto).
 */
final class FiltroFamiglie
{
    private const GRUPPI = ['famiglia', 'macrofamiglia', 'microfamiglia'];

    /**
     * Valori distinti presenti (non vuoti), ordinati, per ogni gruppo.
     *
     * @param  list<CaricoProduzione>  $carichi
     * @return array{famiglia:list<string>, macrofamiglia:list<string>, microfamiglia:list<string>}
     */
    public static function opzioni(array $carichi): array
    {
        $set = ['famiglia' => [], 'macrofamiglia' => [], 'microfamiglia' => []];
        foreach ($carichi as $c) {
            foreach (self::GRUPPI as $g) {
                $v = $c->{$g};
                if ($v !== '') {
                    $set[$g][$v] = true;
                }
            }
        }

        $out = [];
        foreach (self::GRUPPI as $g) {
            $valori = array_keys($set[$g]);
            sort($valori, SORT_NATURAL | SORT_FLAG_CASE);
            $out[$g] = array_values($valori);
        }

        return $out;
    }

    /**
     * Tiene solo i carichi la cui classificazione rientra nelle selezioni. Un gruppo con selezione
     * vuota non filtra (tutto ammesso).
     *
     * @param  list<CaricoProduzione>  $carichi
     * @param  array{famiglia?:list<string>, macrofamiglia?:list<string>, microfamiglia?:list<string>}  $selezioni
     * @return list<CaricoProduzione>
     */
    public static function applica(array $carichi, array $selezioni): array
    {
        $sel = [];
        $attivo = false;
        foreach (self::GRUPPI as $g) {
            $sel[$g] = array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                (array) ($selezioni[$g] ?? []),
            ), static fn (string $v) => $v !== ''));
            if ($sel[$g] !== []) {
                $attivo = true;
            }
        }

        if (! $attivo) {
            return array_values($carichi);
        }

        return array_values(array_filter($carichi, static function (CaricoProduzione $c) use ($sel): bool {
            foreach (self::GRUPPI as $g) {
                if ($sel[$g] !== [] && ! in_array($c->{$g}, $sel[$g], true)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
