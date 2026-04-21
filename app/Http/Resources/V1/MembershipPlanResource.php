<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MembershipPlan
 */
class MembershipPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency?->code,
            'currency_symbol' => $this->currency?->symbol,
            'duration_days' => $this->duration_days,
            'is_active' => $this->is_active,
        ];
    }
}
