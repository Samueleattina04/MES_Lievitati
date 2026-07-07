<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TipoFaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->eAdmin();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'codice' => ['required', 'string', 'max:50', Rule::unique('tipi_fase', 'codice')->ignore($this->route('tipoFase'))],
            'descrizione' => ['required', 'string', 'max:150'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.reparto_id' => ['required', 'integer', 'exists:reparti,id'],
            'steps.*.descrizione' => ['nullable', 'string', 'max:150'],
            'steps.*.consuma_materiali' => ['boolean'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'steps.required' => 'Definisci almeno uno step (reparto) per la fase.',
            'steps.min' => 'Definisci almeno uno step (reparto) per la fase.',
        ];
    }
}
