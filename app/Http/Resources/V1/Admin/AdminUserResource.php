<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->whenLoaded('companyMemberships', fn () => $this->companyMemberships);
        $firstMembership = is_iterable($membership) ? collect($membership)->first() : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all(), []),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'invitation_sent_at' => $this->invitation_token !== null
                ? $this->updated_at?->toIso8601String()
                : null,
            'invitation_expires_at' => $this->invitation_expires_at?->toIso8601String(),
            'invitation_accepted_at' => $this->invitation_accepted_at?->toIso8601String(),
            'is_invited' => $this->invitation_token !== null && $this->invitation_accepted_at === null,
            'company' => $firstMembership !== null && $firstMembership->company !== null
                ? [
                    'id' => $firstMembership->company->id,
                    'legal_name' => $firstMembership->company->legal_name,
                    'trade_name' => $firstMembership->company->trade_name,
                    'member_role' => $firstMembership->role?->value,
                    'job_title' => $firstMembership->job_title,
                ]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
