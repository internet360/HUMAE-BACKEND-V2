<?php

declare(strict_types=1);

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

class CourseRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:200'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:200'],
            'duration_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65000'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
            'certificate_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
