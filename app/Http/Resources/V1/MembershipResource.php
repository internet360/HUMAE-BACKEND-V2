<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Membership
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'auto_renew' => $this->auto_renew,
            'is_active' => $this->status?->value === 'active'
                && $this->expires_at !== null
                && $this->expires_at->isFuture(),
            'plan' => MembershipPlanResource::make($this->whenLoaded('plan')),
        ];
    }
}
