<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

class EducationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'degree_level_id' => ['sometimes', 'nullable', 'integer', 'exists:degree_levels,id'],
            'institution' => [$required, 'string', 'max:200'],
            'field_of_study' => ['sometimes', 'nullable', 'string', 'max:200'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'nullable', 'in:en_curso,concluido,trunco,titulado'],
            'credential_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
