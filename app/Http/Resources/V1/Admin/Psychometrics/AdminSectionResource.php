<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin\Psychometrics;

use App\Models\PsychometricTestSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricTestSection
 */
class AdminSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->psychometric_test_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'time_limit_minutes' => $this->time_limit_minutes,
            'sort_order' => $this->sort_order,
        ];
    }
}
