<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\CandidateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateProfile
 */
class CandidateProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'headline' => $this->headline,
            'summary' => $this->summary,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'curp' => $this->curp,
            'rfc' => $this->rfc,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'whatsapp' => $this->whatsapp,
            'linkedin_url' => $this->linkedin_url,
            'portfolio_url' => $this->portfolio_url,
            'github_url' => $this->github_url,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'address_line' => $this->address_line,
            'postal_code' => $this->postal_code,
            'career_level_id' => $this->career_level_id,
            'functional_area_id' => $this->functional_area_id,
            'position_id' => $this->position_id,
            'candidate_kind' => $this->candidate_kind?->value,
            'other_area_text' => $this->other_area_text,
            'functional_areas' => $this->whenLoaded('functionalAreas', fn () => $this->functionalAreas->map(fn ($a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'is_primary' => (bool) $a->getRelation('pivot')?->getAttribute('is_primary'),
                'sort_order' => (int) ($a->getRelation('pivot')?->getAttribute('sort_order') ?? 0),
            ])->values()),
            'years_of_experience' => $this->years_of_experience,
            'salary_currency_id' => $this->salary_currency_id,
            'expected_salary_min' => $this->expected_salary_min !== null
                ? (float) $this->expected_salary_min
                : null,
            'expected_salary_max' => $this->expected_salary_max !== null
                ? (float) $this->expected_salary_max
                : null,
            'expected_salary_period' => $this->expected_salary_period,
            'availability' => $this->availability,
            'available_from' => $this->available_from?->toDateString(),
            'open_to_relocation' => (bool) $this->open_to_relocation,
            'open_to_remote' => (bool) $this->open_to_remote,
            'state' => $this->state?->value,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'experiences' => ExperienceResource::collection($this->whenLoaded('experiences')),
            'educations' => EducationResource::collection($this->whenLoaded('educations')),
            'courses' => CourseResource::collection($this->whenLoaded('courses')),
            'certifications' => CertificationResource::collection($this->whenLoaded('certifications')),
            'references' => ReferenceResource::collection($this->whenLoaded('references')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'skills' => SkillPivotResource::collection($this->whenLoaded('skills')),
            'languages' => LanguagePivotResource::collection($this->whenLoaded('languages')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
