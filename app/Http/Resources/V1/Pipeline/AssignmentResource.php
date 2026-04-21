<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Pipeline;

use App\Enums\AssignmentStage;
use App\Models\VacancyAssignment;
use App\Services\AssignmentStageMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VacancyAssignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stage = $this->stage ?? AssignmentStage::Sourced;
        $profile = $this->candidateProfile;

        return [
            'id' => $this->id,
            'vacancy_id' => $this->vacancy_id,
            'candidate_profile_id' => $this->candidate_profile_id,
            'assigned_by' => $this->assigned_by,
            'stage' => $stage->value,
            'priority' => $this->priority?->value,
            'score' => $this->score,
            'recruiter_notes' => $this->recruiter_notes,
            'rejection_reason' => $this->rejection_reason,
            'allowed_transitions' => AssignmentStageMachine::allowedValuesFrom($stage),
            'candidate' => $profile !== null ? [
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'headline' => $profile->headline,
                'years_of_experience' => $profile->years_of_experience,
                'avatar_url' => $profile->user?->avatar_url,
            ] : null,
            'presented_at' => $this->presented_at?->toIso8601String(),
            'interviewed_at' => $this->interviewed_at?->toIso8601String(),
            'offer_sent_at' => $this->offer_sent_at?->toIso8601String(),
            'hired_at' => $this->hired_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'notes' => AssignmentNoteResource::collection(
                $this->whenLoaded('notes'),
            ),
        ];
    }
}
