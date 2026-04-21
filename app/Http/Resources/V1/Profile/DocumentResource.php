<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Profile;

use App\Models\CandidateDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidateDocument
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'file_url' => $this->file_url,
            'mime_type' => $this->mime_type,
            'file_size_bytes' => $this->file_size_bytes,
            'is_internal' => (bool) $this->is_internal,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
        ];
    }
}
