<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Psychometrics;

use Illuminate\Foundation\Http\FormRequest;

class CancelAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('psychometric.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Requerido, no opcional: anular la medición de una persona sin dejar
            // dicho por qué convierte la auditoría en un registro inútil.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indica por qué se anula el intento: queda registrado.',
        ];
    }
}
