<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\CandidateEducation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateEducation
 */
class EducationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'degree_level_id' => $this->degree_level_id,
            'institution' => $this->institution,
            'field_of_study' => $this->field_of_study,
            'location' => $this->location,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_current' => (bool) $this->is_current,
            'status' => $this->status,
            'credential_number' => $this->credential_number,
            'sort_order' => $this->sort_order,
        ];
    }
}
