<?php

declare(strict_types=1);

namespace App\Http\Requests\Interviews;

use App\Enums\InterviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleInterviewRequest extends FormRequest
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
        $modes = array_map(fn (InterviewMode $m) => $m->value, InterviewMode::cases());

        return [
            'vacancy_assignment_id' => ['required', 'integer', 'exists:vacancy_assignments,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'round' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'mode' => ['sometimes', Rule::in($modes)],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'timezone' => ['sometimes', 'string', 'max:60'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:600'],
            'meeting_provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'meeting_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:400'],
        ];
    }
}
