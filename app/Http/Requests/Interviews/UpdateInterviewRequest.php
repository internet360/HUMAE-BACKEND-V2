<?php

declare(strict_types=1);

namespace App\Http\Requests\Interviews;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInterviewRequest extends FormRequest
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
            'scheduled_at' => ['sometimes', 'date', 'after:now'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:600'],
            'location' => ['sometimes', 'nullable', 'string', 'max:400'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10'],
            'recruiter_feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'company_feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'recommendation' => ['sometimes', 'nullable', 'in:advance,hold,reject'],
        ];
    }
}
