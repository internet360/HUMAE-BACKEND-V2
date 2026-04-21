<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricAttempt
 */
class AttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_id' => $this->psychometric_test_id,
            'status' => $this->status?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'answers' => AnswerResource::collection($this->whenLoaded('answers')),
            'test' => TestResource::make($this->whenLoaded('test')),
            'result' => ResultResource::make($this->whenLoaded('result')),
        ];
    }
}
