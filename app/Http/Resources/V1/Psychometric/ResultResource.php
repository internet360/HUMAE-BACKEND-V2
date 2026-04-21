<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricResult
 */
class ResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_id' => $this->psychometric_attempt_id,
            'total_score' => $this->total_score !== null
                ? (float) $this->total_score
                : null,
            'percentile' => $this->percentile !== null
                ? (float) $this->percentile
                : null,
            'grade' => $this->grade,
            'passed' => (bool) $this->passed,
            'dimension_scores' => $this->dimension_scores,
            'summary' => $this->summary,
            'recommendations' => $this->recommendations,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
