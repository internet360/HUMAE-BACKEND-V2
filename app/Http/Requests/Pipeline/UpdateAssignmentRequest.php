<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use App\Enums\AssignmentStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssignmentRequest extends FormRequest
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
        $stages = array_map(fn (AssignmentStage $s) => $s->value, AssignmentStage::cases());

        return [
            'stage' => ['sometimes', Rule::in($stages)],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'score' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'recruiter_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:stage,rejected', 'nullable', 'string', 'min:3', 'max:2000'],
        ];
    }
}
