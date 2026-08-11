<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Intento + resultado vistos por la empresa cliente.
 *
 * Recurso aparte del de HUMAE, no un `when()` sobre el mismo, para que la
 * diferencia sea visible al leer y no dependa de acertar una condición: acá se
 * ve la MEDICIÓN, no las anotaciones internas de HUMAE.
 *
 * Fuera a propósito:
 *  - `percentile` y `recommendations`: son el juicio de HUMAE sobre el
 *    candidato, no el dato medido. §6 le cierra a la empresa el expediente
 *    completo; con más razón la valoración interna.
 *  - `reviewed_at` / quién revisó: proceso interno.
 *  - metadatos de la rendición (duración, estado): no aportan a la decisión de
 *    contratar y sí dicen cómo operamos.
 *
 * @mixin PsychometricAttempt
 */
class CompanyAttemptResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->result;

        return [
            'attempt_id' => $this->id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            'test' => $this->test !== null ? [
                'name' => $this->test->name,
                'category' => $this->test->category,
            ] : null,

            'result' => $result !== null ? [
                'total_score' => (float) $result->total_score,
                'grade' => $result->grade,
                'passed' => $result->passed,
                'dimension_scores' => $result->dimension_scores,
                'summary' => $result->summary,
            ] : null,
        ];
    }
}
