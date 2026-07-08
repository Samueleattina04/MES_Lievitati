<?php

namespace Database\Factories;

use App\Enums\RuoloUtente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Utente "office" di default (non operatore): un utente di test loggato va alla dashboard,
            // non alla coda operatore. Gli operatori (PIN) si creano con uno state dedicato.
            'ruolo' => RuoloUtente::Backoffice,
            'attivo' => true,
        ];
    }

    /**
     * Operatore di reparto: accesso via PIN, niente email/password.
     */
    public function operatore(string $pin = '0000'): static
    {
        return $this->state(fn (array $attributes) => [
            'ruolo' => RuoloUtente::Operatore,
            'email' => null,
            'password' => null,
            'pin_hash' => Hash::make($pin),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
