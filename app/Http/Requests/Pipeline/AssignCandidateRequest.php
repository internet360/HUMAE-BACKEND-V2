<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class AssignCandidateRequest extends FormRequest
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
            'candidate_profile_id' => ['required', 'integer', 'exists:candidate_profiles,id'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'score' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'recruiter_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
