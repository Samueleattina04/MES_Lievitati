<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepartoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->eAdmin();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'codice' => ['required', 'string', 'max:50', Rule::unique('reparti', 'codice')->ignore($this->route('reparto'))],
            'descrizione' => ['required', 'string', 'max:150'],
            'attivo' => ['boolean'],
        ];
    }
}
