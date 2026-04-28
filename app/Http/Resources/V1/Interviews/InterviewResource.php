<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Interviews;

use App\Enums\InterviewState;
use App\Enums\UserRole;
use App\Models\Interview;
use App\Models\User;
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

        /** @var User|null $user */
        $user = $request->user();
        // El feedback (recruiter + empresa) es información interna de evaluación.
        // Los candidatos NO deben verla; recruiter, admin y company_user sí.
        $canSeeFeedback = $user !== null
            && $user->hasAnyRole([
                UserRole::Recruiter->value,
                UserRole::Admin->value,
                UserRole::CompanyUser->value,
            ]);

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
            'rating' => $this->when($canSeeFeedback, fn () => $this->rating),
            'recommendation' => $this->when($canSeeFeedback, fn () => $this->recommendation),
            'recruiter_feedback' => $this->when($canSeeFeedback, fn () => $this->recruiter_feedback),
            'company_feedback' => $this->when($canSeeFeedback, fn () => $this->company_feedback),
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
