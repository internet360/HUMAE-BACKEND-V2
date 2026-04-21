<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Directory;

use App\Http\Resources\V1\Profile\CandidateProfileResource;
use App\Models\CandidateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateProfile
 *
 * Expediente completo para reclutadores: reutiliza el resource canónico del
 * perfil y añade metadatos propios del directorio.
 */
class DirectoryCandidateDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = CandidateProfileResource::make($this->resource)->toArray($request);

        /** @var array<int, int> $favoriteIds */
        $favoriteIds = (array) ($request->attributes->get('directory.favorite_ids') ?? []);

        $profile['is_favorite'] = in_array($this->id, $favoriteIds, true);
        $profile['user'] = [
            'id' => $this->user?->id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'avatar_url' => $this->user?->avatar_url,
        ];

        return $profile;
    }
}
