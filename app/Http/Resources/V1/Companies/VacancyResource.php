<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Models\Vacancy;
use App\Services\VacancyStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vacancy
 */
class VacancyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->state;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'responsibilities' => $this->responsibilities,
            'requirements' => $this->requirements,
            'benefits' => $this->benefits,

            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company?->id,
                'legal_name' => $this->company?->legal_name,
                'trade_name' => $this->company?->trade_name,
            ]),

            'created_by' => $this->created_by,
            'assigned_recruiter_id' => $this->assigned_recruiter_id,

            'position_id' => $this->position_id,
            'functional_area_id' => $this->functional_area_id,
            'vacancy_category_id' => $this->vacancy_category_id,
            'target_candidate_kind' => $this->target_candidate_kind->value,
            'vacancy_type_id' => $this->vacancy_type_id,
            'vacancy_shift_id' => $this->vacancy_shift_id,
            'career_level_id' => $this->career_level_id,
            'degree_level_id' => $this->degree_level_id,

            'min_years_of_experience' => $this->min_years_of_experience,
            'max_years_of_experience' => $this->max_years_of_experience,
            'vacancies_count' => $this->vacancies_count,

            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'is_remote' => (bool) $this->is_remote,
            'is_hybrid' => (bool) $this->is_hybrid,
            'work_location' => $this->work_location,

            'salary_currency_id' => $this->salary_currency_id,
            'salary_min' => $this->salary_min !== null ? (float) $this->salary_min : null,
            'salary_max' => $this->salary_max !== null ? (float) $this->salary_max : null,
            'salary_period' => $this->salary_period,
            'salary_is_public' => (bool) $this->salary_is_public,

            'state' => $state?->value,
            'allowed_transitions' => $state !== null
                ? VacancyStateMachine::allowedValuesFrom($state)
                : [],
            'priority' => $this->priority?->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'closes_at' => $this->closes_at?->toDateString(),
            'filled_at' => $this->filled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'fee_amount' => $this->fee_amount !== null ? (float) $this->fee_amount : null,
            'fee_percentage' => $this->fee_percentage !== null ? (float) $this->fee_percentage : null,
            'sla_days' => $this->sla_days,
            'internal_notes' => $this->when(
                $this->canSeeInternalNotes($request),
                $this->internal_notes,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function canSeeInternalNotes(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(['recruiter', 'admin']);
    }
}
