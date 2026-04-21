<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Interviews;

use App\Enums\InterviewState;
use App\Models\Interview;
use App\Services\InterviewStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Interview
 */
class InterviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->state ?? InterviewState::Propuesta;

        return [
            'id' => $this->id,
            'vacancy_assignment_id' => $this->vacancy_assignment_id,
            'round' => $this->round,
            'title' => $this->title,
            'state' => $state->value,
            'mode' => $this->mode?->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'timezone' => $this->timezone,
            'meeting_url' => $this->meeting_url,
            'meeting_provider' => $this->meeting_provider,
            'meeting_id' => $this->meeting_id,
            'location' => $this->location,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'rating' => $this->rating,
            'recommendation' => $this->recommendation,
            'allowed_transitions' => InterviewStateMachine::allowedValuesFrom($state),
            'assignment' => $this->whenLoaded('assignment', fn () => [
                'id' => $this->assignment?->id,
                'vacancy_id' => $this->assignment?->vacancy_id,
                'candidate_profile_id' => $this->assignment?->candidate_profile_id,
                'candidate' => $this->assignment?->candidateProfile !== null ? [
                    'first_name' => $this->assignment->candidateProfile->first_name,
                    'last_name' => $this->assignment->candidateProfile->last_name,
                ] : null,
                'vacancy' => $this->assignment?->vacancy !== null ? [
                    'title' => $this->assignment->vacancy->title,
                    'code' => $this->assignment->vacancy->code,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
