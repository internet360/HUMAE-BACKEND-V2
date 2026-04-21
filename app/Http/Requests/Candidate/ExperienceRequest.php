<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

class ExperienceRequest extends FormRequest
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
            'company_name' => [$required, 'string', 'max:200'],
            'position_title' => [$required, 'string', 'max:200'],
            'functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'start_date' => [$required, 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'achievements' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
