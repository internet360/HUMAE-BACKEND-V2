<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'slug' => $this->slug,
            'rfc' => $this->rfc,
            'description' => $this->description,
            'website' => $this->website,
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'industry_id' => $this->industry_id,
            'company_size_id' => $this->company_size_id,
            'ownership_type_id' => $this->ownership_type_id,
            'founded_year' => $this->founded_year,
            'contact' => [
                'name' => $this->contact_name,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
                'position' => $this->contact_position,
            ],
            'location' => [
                'country_id' => $this->country_id,
                'state_id' => $this->state_id,
                'city_id' => $this->city_id,
                'address_line' => $this->address_line,
                'postal_code' => $this->postal_code,
            ],
            'socials' => [
                'linkedin' => $this->linkedin_url,
                'facebook' => $this->facebook_url,
                'instagram' => $this->instagram_url,
                'twitter' => $this->twitter_url,
            ],
            'status' => $this->status,
            'is_verified' => $this->is_verified,
            'account_manager_id' => $this->account_manager_id,
            'members' => CompanyMemberResource::collection(
                $this->whenLoaded('members'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
