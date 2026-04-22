<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Pipeline;

use App\Enums\AssignmentStage;
use App\Models\VacancyAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista reducida del pipeline para el `company_user`: omite `recruiter_notes`,
 * `rejection_reason`, y datos de contacto directo del candidato (PII).
 *
 * @mixin VacancyAssignment
 */
class CompanyAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stage = $this->stage ?? AssignmentStage::Presented;
        $profile = $this->candidateProfile;

        return [
            'id' => $this->id,
            'vacancy_id' => $this->vacancy_id,
            'candidate_profile_id' => $this->candidate_profile_id,
            'stage' => $stage->value,
            'priority' => $this->priority?->value,
            'score' => $this->score,
            'candidate' => $profile !== null ? [
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'headline' => $profile->headline,
                'summary' => $profile->summary,
                'years_of_experience' => $profile->years_of_experience,
                'avatar_url' => $profile->user?->avatar_url,
                'skills' => $profile->relationLoaded('skills')
                    ? $profile->skills->map(fn ($skill) => [
                        'id' => $skill->id,
                        'name' => $skill->name,
                        'level' => $skill->pivot->level ?? null,
                    ])->all()
                    : [],
            ] : null,
            'presented_at' => $this->presented_at?->toIso8601String(),
            'interviewed_at' => $this->interviewed_at?->toIso8601String(),
            'hired_at' => $this->hired_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
