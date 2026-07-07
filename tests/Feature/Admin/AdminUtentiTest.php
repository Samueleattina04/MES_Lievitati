<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\RuoloUtente;
use App\Models\Reparto;
use App\Models\User;
use Database\Seeders\MesConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Test del pannello Admin (§7): accesso ristretto, creazione utenti differenziata, unicità PIN,
 * e disabilitazione della registrazione pubblica. Richiede DB (gira sul server).
 */
final class AdminUtentiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MesConfigSeeder::class);
    }

    private function admin(): User
    {
        return User::where('ruolo', RuoloUtente::Admin->value)->firstOrFail();
    }

    public function test_registrazione_pubblica_disabilitata(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_solo_admin_accede_al_pannello(): void
    {
        $backoffice = User::where('ruolo', RuoloUtente::Backoffice->value)->firstOrFail();
        $this->actingAs($backoffice)->get(route('admin.utenti.index'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.utenti.index'))->assertOk();
    }

    public function test_crea_operatore_con_pin_e_reparti(): void
    {
        $reparti = Reparto::whereIn('codice', ['IMP', 'FORNO'])->pluck('id')->all();

        $this->actingAs($this->admin())->post(route('admin.utenti.store'), [
            'name' => 'Nuovo Operatore',
            'ruolo' => 'operatore',
            'pin' => '9876',
            'reparti' => $reparti,
            'attivo' => true,
        ])->assertSessionHasNoErrors();

        $u = User::where('name', 'Nuovo Operatore')->firstOrFail();
        self::assertSame(RuoloUtente::Operatore, $u->ruolo);
        self::assertNull($u->email);
        self::assertNotNull($u->pin_hash);
        self::assertTrue(Hash::check('9876', $u->pin_hash));
        self::assertEqualsCanonicalizing($reparti, $u->reparti->pluck('id')->all());
    }

    public function test_pin_duplicato_rifiutato(): void
    {
        // Mario Rossi ha gia' il PIN 1234 (dal seeder).
        $this->actingAs($this->admin())->post(route('admin.utenti.store'), [
            'name' => 'Operatore Clone',
            'ruolo' => 'operatore',
            'pin' => '1234',
            'reparti' => [],
        ])->assertSessionHasErrors('pin');

        self::assertNull(User::where('name', 'Operatore Clone')->first());
    }

    public function test_crea_staff_con_email_e_password_hashata(): void
    {
        $this->actingAs($this->admin())->post(route('admin.utenti.store'), [
            'name' => 'Nuovo Backoffice',
            'ruolo' => 'backoffice',
            'email' => 'nuovo.bo@lievitati.local',
            'password' => 'segretissima',
            'attivo' => true,
        ])->assertSessionHasNoErrors();

        $u = User::where('email', 'nuovo.bo@lievitati.local')->firstOrFail();
        self::assertSame(RuoloUtente::Backoffice, $u->ruolo);
        self::assertNull($u->pin_hash);
        self::assertTrue(Hash::check('segretissima', $u->password));
    }

    public function test_pin_obbligatorio_per_nuovo_operatore(): void
    {
        $this->actingAs($this->admin())->post(route('admin.utenti.store'), [
            'name' => 'Senza Pin',
            'ruolo' => 'operatore',
            'reparti' => [],
        ])->assertSessionHasErrors('pin');
    }
}
