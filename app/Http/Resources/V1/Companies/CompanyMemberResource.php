<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Models\CompanyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyMember
 */
class CompanyMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ],
            'role' => $this->role?->value,
            'job_title' => $this->job_title,
            'is_primary_contact' => (bool) $this->is_primary_contact,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
        ];
    }
}
