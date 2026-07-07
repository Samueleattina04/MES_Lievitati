<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RuoloUtente;
use App\Rules\PinUnico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UtenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->eAdmin();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $utente = $this->route('utente');
        $id = $utente?->id;
        $operatore = $this->input('ruolo') === RuoloUtente::Operatore->value;
        $creating = $this->isMethod('post');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'ruolo' => ['required', Rule::enum(RuoloUtente::class)],
            'attivo' => ['boolean'],
        ];

        if ($operatore) {
            // PIN obbligatorio in creazione (o se l'utente non ne ha ancora); in modifica se
            // lasciato vuoto resta invariato. Sempre validata l'unicità.
            $pinObbligatorio = $creating || ($utente !== null && $utente->pin_hash === null);
            $min = (int) config('mes.pin.min_length', 4);
            $max = (int) config('mes.pin.max_length', 6);

            $rules['pin'] = [
                $pinObbligatorio ? 'required' : 'nullable',
                "digits_between:{$min},{$max}",
                new PinUnico($id),
            ];
            $rules['reparti'] = ['array'];
            $rules['reparti.*'] = ['integer', 'exists:reparti,id'];
        } else {
            $rules['email'] = ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)];
            $rules['password'] = $creating
                ? ['required', 'string', 'min:6']
                : ['nullable', 'string', 'min:6'];
        }

        return $rules;
    }
}
