<?php

declare(strict_types=1);

namespace Tests\Unit\CarichiProduzione;

use App\CarichiProduzione\CaricoProduzione;
use App\CarichiProduzione\FiltroFamiglie;
use PHPUnit\Framework\TestCase;

/**
 * Il filtro famiglie (§ Carichi) ricava le opzioni dai carichi e applica la selezione a checklist.
 * Gruppo con selezione vuota = nessun filtro. Logica pura, nessun DB.
 */
final class FiltroFamiglieTest extends TestCase
{
    private function c(string $art, string $fam, string $macro, string $micro): CaricoProduzione
    {
        return new CaricoProduzione(
            data: null, articolo: $art, descrizione: '', lotto: '', quantita: 0, um: '', magazzino: '',
            lottoPf: '', causale: '', codOpMag: '850', operatore: 'N.CELESTINO',
            famiglia: $fam, macrofamiglia: $macro, microfamiglia: $micro,
        );
    }

    /** @return list<CaricoProduzione> */
    private function dati(): array
    {
        return [
            $this->c('A', 'Lievitati', 'Dolci', 'Panettoni'),
            $this->c('B', 'Lievitati', 'Dolci', 'Colombe'),
            $this->c('C', 'Torroni', 'Dolci', 'Morbidi'),
            $this->c('D', '', 'Dolci', ''), // famiglia/micro vuote: non entrano nelle opzioni
        ];
    }

    public function test_opzioni_valori_distinti_non_vuoti_ordinati(): void
    {
        $o = FiltroFamiglie::opzioni($this->dati());

        self::assertSame(['Lievitati', 'Torroni'], $o['famiglia']);
        self::assertSame(['Dolci'], $o['macrofamiglia']);
        self::assertSame(['Colombe', 'Morbidi', 'Panettoni'], $o['microfamiglia']);
    }

    public function test_selezione_vuota_non_filtra(): void
    {
        $out = FiltroFamiglie::applica($this->dati(), ['famiglia' => [], 'macrofamiglia' => [], 'microfamiglia' => []]);

        self::assertCount(4, $out);
    }

    public function test_filtra_per_famiglia(): void
    {
        $out = FiltroFamiglie::applica($this->dati(), ['famiglia' => ['Lievitati']]);

        self::assertSame(['A', 'B'], array_map(static fn (CaricoProduzione $c) => $c->articolo, $out));
    }

    public function test_filtri_su_piu_gruppi_in_and(): void
    {
        $out = FiltroFamiglie::applica($this->dati(), [
            'famiglia' => ['Lievitati'],
            'microfamiglia' => ['Colombe'],
        ]);

        self::assertSame(['B'], array_map(static fn (CaricoProduzione $c) => $c->articolo, $out));
    }
}
