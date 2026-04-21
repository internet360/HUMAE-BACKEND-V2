<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricQuestion
 */
class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section_id' => $this->psychometric_test_section_id,
            'type' => $this->type?->value,
            'prompt' => $this->prompt,
            'image_url' => $this->image_url,
            'sort_order' => $this->sort_order,
            'options' => QuestionOptionResource::collection(
                $this->whenLoaded('options'),
            ),
        ];
    }
}
