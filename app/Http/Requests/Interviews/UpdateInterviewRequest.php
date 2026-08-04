<?php

declare(strict_types=1);

namespace App\Http\Requests\Interviews;

use App\Http\Requests\Concerns\RestrictsFieldsByRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rescheduling an interview and evaluating one are different rights sharing an
 * endpoint. §5.8 lets a client company reschedule; §6 keeps the evaluation
 * internal ("Agregar notas internas: Empresa cliente ❌").
 *
 * This Request used to state neither, while its sibling
 * {@see ScheduleInterviewRequest} stated the meeting-link half — same resource,
 * one rule written and the other forgotten (F-08).
 */
class UpdateInterviewRequest extends FormRequest
{
    use RestrictsFieldsByRole;

    /**
     * The interviewer's assessment, and the meeting details HUMAE publishes
     * after the candidate picks a slot. `location` is here for symmetry with
     * the schedule endpoint, which forbids it outright while interviews are
     * online-only.
     *
     * `company_feedback` is deliberately absent: it is the company's own
     * opinion and hers to write.
     *
     * @return list<string>
     */
    protected function staffOnlyFields(): array
    {
        return [
            'rating',
            'recommendation',
            'recruiter_feedback',
            'meeting_url',
            'meeting_provider',
            'meeting_id',
            'location',
        ];
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
            'meeting_provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'meeting_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:400'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10'],
            'recruiter_feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'company_feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'recommendation' => ['sometimes', 'nullable', 'in:advance,hold,reject'],
        ];
    }
}
