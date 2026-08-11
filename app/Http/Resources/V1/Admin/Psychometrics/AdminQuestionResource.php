<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin\Psychometrics;

use App\Models\PsychometricQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pregunta vista por el admin: incluye `dimension`, `weight` e
 * `is_reverse_scored`, que el recurso del candidato oculta.
 *
 * @mixin PsychometricQuestion
 */
class AdminQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->psychometric_test_id,
            'section_id' => $this->psychometric_test_section_id,
            'type' => $this->type?->value,
            'prompt' => $this->prompt,
            'image_url' => $this->image_url,
            'dimension' => $this->dimension,
            'weight' => $this->weight,
            'is_reverse_scored' => $this->is_reverse_scored,
            'sort_order' => $this->sort_order,
            'options' => AdminOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
