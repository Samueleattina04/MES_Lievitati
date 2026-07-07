<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticoloConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->eAdmin();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'articolo_codice' => [
                'required', 'string', 'max:100',
                Rule::unique('articolo_configurazione_mes', 'articolo_codice')->ignore($this->route('config')),
            ],
            'reparto_default_id' => ['nullable', 'integer', 'exists:reparti,id'],
            'tipo_fase_id' => ['nullable', 'integer', 'exists:tipi_fase,id'],
            'flag_lotto_override' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
