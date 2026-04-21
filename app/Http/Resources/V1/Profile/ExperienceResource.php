<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\CandidateExperience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateExperience
 */
class ExperienceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'position_title' => $this->position_title,
            'functional_area_id' => $this->functional_area_id,
            'location' => $this->location,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_current' => (bool) $this->is_current,
            'description' => $this->description,
            'achievements' => $this->achievements,
            'sort_order' => $this->sort_order,
        ];
    }
}
