<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\RuoloUtente;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * Unicità del PIN operatore (§7). I PIN sono salvati hashati (bcrypt, salt diverso per riga),
 * quindi non si può usare un unique SQL: si confronta il PIN in chiaro con Hash::check su tutti
 * gli operatori (escluso eventualmente l'utente in modifica).
 */
final class PinUnico implements ValidationRule
{
    public function __construct(
        private readonly ?int $exceptUserId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;

        $operatori = User::query()
            ->where('ruolo', RuoloUtente::Operatore->value)
            ->whereNotNull('pin_hash')
            ->when($this->exceptUserId !== null, fn ($q) => $q->where('id', '!=', $this->exceptUserId))
            ->get();

        foreach ($operatori as $operatore) {
            if (Hash::check($pin, (string) $operatore->pin_hash)) {
                $fail('Questo PIN è già assegnato a un altro operatore.');

                return;
            }
        }
    }
}
