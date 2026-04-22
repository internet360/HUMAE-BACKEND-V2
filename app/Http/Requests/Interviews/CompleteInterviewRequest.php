<?php

declare(strict_types=1);

namespace App\Http\Requests\Interviews;

use Illuminate\Foundation\Http\FormRequest;

class CompleteInterviewRequest extends FormRequest
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
        return [
            'recruiter_feedback' => ['required', 'string', 'min:5', 'max:5000'],
            'recommendation' => ['required', 'in:advance,hold,reject'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10'],
        ];
    }
}
