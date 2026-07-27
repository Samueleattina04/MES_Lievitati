<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrdineRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gestione ordini: Pianificazione + Admin + Backoffice (change #3). Fonte unica: RuoloUtente.
        return (bool) $this->user()?->ruolo?->puoGestireOrdini();
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'articolo_finito_codice' => ['required', 'string', 'max:100'],
            'quantita' => ['required', 'numeric', 'gt:0'],
            'data' => ['nullable', 'date'],
            'numero' => ['nullable', 'string', 'max:50', 'unique:ordini_produzione,numero'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'articolo_finito_codice.required' => 'Seleziona un articolo da produrre.',
            'quantita.gt' => 'La quantita deve essere maggiore di zero.',
        ];
    }
}
