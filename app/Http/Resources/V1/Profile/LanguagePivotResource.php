<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\Language;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Language
 */
class LanguagePivotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Language $language */
        $language = $this->resource;

        /** @var Pivot|null $pivot */
        $pivot = $language->pivot ?? null;

        return [
            'id' => $language->id,
            'code' => $language->code,
            'name' => $language->name,
            'native_name' => $language->native_name,
            'level' => $pivot?->getAttribute('level'),
        ];
    }
}
