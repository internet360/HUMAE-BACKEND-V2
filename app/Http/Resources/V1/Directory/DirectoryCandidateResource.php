<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Directory;

use App\Models\CandidateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateProfile
 *
 * Vista de listado del directorio: campos compactos + flag `is_favorite`.
 */
class DirectoryCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<int, int> $favoriteIds */
        $favoriteIds = (array) ($request->attributes->get('directory.favorite_ids') ?? []);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'headline' => $this->headline,
            'avatar_url' => $this->user?->avatar_url,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city_id' => $this->city_id,
            'career_level_id' => $this->career_level_id,
            'functional_area_id' => $this->functional_area_id,
            'position_id' => $this->position_id,
            'years_of_experience' => $this->years_of_experience,
            'availability' => $this->availability,
            'open_to_remote' => (bool) $this->open_to_remote,
            'open_to_relocation' => (bool) $this->open_to_relocation,
            'expected_salary_min' => $this->expected_salary_min !== null
                ? (float) $this->expected_salary_min
                : null,
            'expected_salary_max' => $this->expected_salary_max !== null
                ? (float) $this->expected_salary_max
                : null,
            'state' => $this->state?->value,
            'is_favorite' => in_array($this->id, $favoriteIds, true),
            'top_skills' => $this->skills
                ->take(5)
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'level' => $s->getRelation('pivot')?->getAttribute('level'),
                ])
                ->values(),
            'languages' => $this->languages
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'code' => $l->code,
                    'level' => $l->getRelation('pivot')?->getAttribute('level'),
                ])
                ->values(),
        ];
    }
}
