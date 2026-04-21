<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\CandidateReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateReference
 */
class ReferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'company' => $this->company,
            'position_title' => $this->position_title,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'sort_order' => $this->sort_order,
        ];
    }
}
