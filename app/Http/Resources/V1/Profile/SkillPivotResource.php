<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Skill
 */
class SkillPivotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Skill $skill */
        $skill = $this->resource;

        /** @var Pivot|null $pivot */
        $pivot = $skill->pivot ?? null;

        return [
            'id' => $skill->id,
            'code' => $skill->code,
            'name' => $skill->name,
            'category' => $skill->category,
            'level' => $pivot?->getAttribute('level'),
            'years_of_experience' => $pivot?->getAttribute('years_of_experience'),
        ];
    }
}
