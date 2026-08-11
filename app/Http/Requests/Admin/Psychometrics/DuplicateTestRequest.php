<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Psychometrics;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Duplicar es la vía para versionar una prueba ya rendida: la estructura de la
 * original está congelada, así que se trabaja sobre la copia.
 */
class DuplicateTestRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9_-]+$/',
                'unique:psychometric_tests,code',
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
