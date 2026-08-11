<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin\Psychometrics;

use App\Models\PsychometricQuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Opción vista por el admin.
 *
 * A diferencia de `Psychometric\QuestionOptionResource` —el del candidato—
 * expone `score` e `is_correct`. Son dos recursos distintos a propósito: si el
 * candidato viera estos campos, tendría la clave de calificación servida.
 *
 * @mixin PsychometricQuestionOption
 */
class AdminOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->psychometric_question_id,
            'label' => $this->label,
            'value' => $this->value,
            'score' => $this->score,
            'is_correct' => $this->is_correct,
            'sort_order' => $this->sort_order,
        ];
    }
}
