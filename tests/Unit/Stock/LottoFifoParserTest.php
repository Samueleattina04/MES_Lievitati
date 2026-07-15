<?php

declare(strict_types=1);

namespace Tests\Unit\Stock;

use App\Stock\LottoFifoParser;
use PHPUnit\Framework\TestCase;

/**
 * Parser puro del codice lotto per l'ordinamento FIFO (§5.2). Nessun DB.
 * Formato: <prodotto>-<GIORNO><ANNO><PROGRESSIVO> (es. 7317-11126110 = giorno 111, anno 26, prog 110).
 */
final class LottoFifoParserTest extends TestCase
{
    public function test_estrae_giorno_anno_progressivo(): void
    {
        self::assertSame(['anno' => 2026, 'giorno' => 111, 'prog' => 110], LottoFifoParser::chiave('7317-11126110'));
    }

    public function test_giorno_a_due_cifre(): void
    {
        self::assertSame(['anno' => 2026, 'giorno' => 76, 'prog' => 110], LottoFifoParser::chiave('7317-7626110'));
    }

    public function test_codice_senza_trattino_non_parsabile(): void
    {
        // Lotto di fornitore esterno: nessuna data estraibile -> fallback.
        self::assertNull(LottoFifoParser::chiave('3126000272'));
    }

    public function test_codice_non_numerico_non_parsabile(): void
    {
        self::assertNull(LottoFifoParser::chiave('7317-ABC26110'));
    }

    public function test_giorno_fuori_range_non_parsabile(): void
    {
        self::assertNull(LottoFifoParser::chiave('7317-99926110')); // giorno 999
    }

    public function test_coda_troppo_corta_non_parsabile(): void
    {
        self::assertNull(LottoFifoParser::chiave('7317-12345')); // solo 5 cifre
    }

    public function test_ordina_dal_piu_vecchio_e_mette_i_non_parsabili_in_coda(): void
    {
        $codici = ['7317-18126110', 'ESTERNO123', '7317-7626110', '7317-12426110'];
        usort($codici, static fn ($a, $b) => LottoFifoParser::confronta(
            LottoFifoParser::chiave($a),
            LottoFifoParser::chiave($b),
        ));

        self::assertSame(['7317-7626110', '7317-12426110', '7317-18126110', 'ESTERNO123'], $codici);
    }

    public function test_progressivo_come_tie_break_stesso_giorno(): void
    {
        self::assertSame(-1, LottoFifoParser::confronta(
            LottoFifoParser::chiave('7317-11126110'),
            LottoFifoParser::chiave('7317-11126111'),
        ));
    }

    public function test_anno_precedente_viene_prima(): void
    {
        // giorno 360 del 2025 prima del giorno 5 del 2026.
        self::assertSame(-1, LottoFifoParser::confronta(
            LottoFifoParser::chiave('7317-36025005'),
            LottoFifoParser::chiave('7317-526005'),
        ));
    }
}
