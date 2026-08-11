<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Intento + resultado vistos por HUMAE (reclutador y admin).
 *
 * Vista completa: incluye las anotaciones internas (`percentile`,
 * `recommendations`, quién revisó) y los metadatos de la rendición. La empresa
 * cliente tiene su propio recurso reducido — ver
 * `CompanyAttemptResultResource`.
 *
 * NO expone las respuestas ítem por ítem. Nadie las necesita para decidir, y
 * publicarlas convertiría el resultado en un cuestionario reconstruible.
 *
 * @mixin PsychometricAttempt
 */
class StaffAttemptResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->result;

        return [
            'attempt_id' => $this->id,
            'status' => $this->status?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,

            // Un intento anulado puede conservar su `result` (si se anuló después
            // de completarse). La UI necesita saberlo para no pintar esas
            // dimensiones como una medición válida.
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_reason' => $this->cancelled_reason,

            'test' => $this->test !== null ? [
                'id' => $this->test->id,
                'code' => $this->test->code,
                'name' => $this->test->name,
                'category' => $this->test->category,
                'passing_score' => $this->test->passing_score,
            ] : null,

            'result' => $result !== null ? [
                'total_score' => (float) $result->total_score,
                'grade' => $result->grade,
                'passed' => $result->passed,
                'percentile' => $result->percentile !== null ? (float) $result->percentile : null,
                'dimension_scores' => $result->dimension_scores,
                'summary' => $result->summary,
                'recommendations' => $result->recommendations,
                'reviewed_at' => $result->reviewed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
