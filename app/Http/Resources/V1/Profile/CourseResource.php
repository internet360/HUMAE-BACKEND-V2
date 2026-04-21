<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\CandidateCourse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateCourse
 */
class CourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'institution' => $this->institution,
            'duration_hours' => $this->duration_hours,
            'completed_at' => $this->completed_at?->toDateString(),
            'certificate_url' => $this->certificate_url,
            'sort_order' => $this->sort_order,
        ];
    }
}
