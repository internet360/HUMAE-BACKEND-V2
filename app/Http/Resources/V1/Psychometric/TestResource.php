<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricTest
 */
class TestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'time_limit_minutes' => $this->time_limit_minutes,
            'instructions' => $this->instructions,
            'is_required' => $this->is_required,
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
            'question_count' => $this->whenLoaded(
                'questions',
                fn () => $this->questions->count(),
            ),
        ];
    }
}
