<?php

declare(strict_types=1);

namespace Tests\Unit\Produzione;

use App\Enums\StatoFase;
use App\Produzione\FaseGate;
use PHPUnit\Framework\TestCase;

/**
 * Test Fase 3 (§14): regole di gating dell'avanzamento fasi/step (precedenze bottom-up).
 */
final class FaseGateTest extends TestCase
{
    public function test_precedenze_soddisfatte_solo_se_tutte_le_figlie_chiuse(): void
    {
        self::assertTrue(FaseGate::precedenzeSoddisfatte([]));
        self::assertTrue(FaseGate::precedenzeSoddisfatte([StatoFase::Chiusa, StatoFase::Chiusa]));
        self::assertFalse(FaseGate::precedenzeSoddisfatte([StatoFase::Chiusa, StatoFase::InCorso]));
        self::assertFalse(FaseGate::precedenzeSoddisfatte([StatoFase::DaLavorare]));
    }

    public function test_primo_step_avviabile_solo_con_precedenze_soddisfatte(): void
    {
        // Primo step (nessuno precedente), precedenze ok, nessuno split mancante.
        self::assertTrue(FaseGate::stepAvviabile(true, false, [], StatoFase::DaLavorare));
        // Precedenze non soddisfatte => bloccato.
        self::assertFalse(FaseGate::stepAvviabile(false, false, [], StatoFase::DaLavorare));
    }

    public function test_primo_step_bloccato_se_split_mancante(): void
    {
        // Fase alimentata da nodo condiviso senza split registrato (§5-bis).
        self::assertFalse(FaseGate::stepAvviabile(true, true, [], StatoFase::DaLavorare));
    }

    public function test_step_successivo_richiede_step_precedente_chiuso(): void
    {
        self::assertTrue(FaseGate::stepAvviabile(true, false, [StatoFase::Chiusa], StatoFase::DaLavorare));
        self::assertFalse(FaseGate::stepAvviabile(true, false, [StatoFase::InCorso], StatoFase::DaLavorare));
    }

    public function test_step_gia_chiuso_non_e_avviabile(): void
    {
        self::assertFalse(FaseGate::stepAvviabile(true, false, [], StatoFase::Chiusa));
    }

    public function test_motivo_blocco_descrive_il_perche(): void
    {
        self::assertNull(FaseGate::motivoBlocco(true, false, [], StatoFase::DaLavorare));
        self::assertStringContainsString('fasi componenti', FaseGate::motivoBlocco(false, false, [], StatoFase::DaLavorare));
        self::assertStringContainsString('split', FaseGate::motivoBlocco(true, true, [], StatoFase::DaLavorare));
        self::assertStringContainsString('step precedente', FaseGate::motivoBlocco(true, false, [StatoFase::InCorso], StatoFase::DaLavorare));
    }

    public function test_tutti_step_chiusi(): void
    {
        self::assertFalse(FaseGate::tuttiStepChiusi([]));
        self::assertTrue(FaseGate::tuttiStepChiusi([StatoFase::Chiusa]));
        self::assertFalse(FaseGate::tuttiStepChiusi([StatoFase::Chiusa, StatoFase::InCorso]));
    }
}
