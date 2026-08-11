<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin\Psychometrics;

use App\Models\PsychometricTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prueba vista por el admin.
 *
 * `is_in_use` y `structure_locked` no son columnas: son el estado que decide qué
 * puede editar la UI. Se exponen para que el front pueda deshabilitar los campos
 * congelados y ofrecer "duplicar" en su lugar, en vez de dejar que el admin
 * intente guardar y se coma un 409.
 *
 * @mixin PsychometricTest
 */
class AdminTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var bool|null $inUse */
        $inUse = $this->resource->is_in_use ?? null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'time_limit_minutes' => $this->time_limit_minutes,
            'passing_score' => $this->passing_score,
            'max_attempts' => $this->max_attempts,
            'instructions' => $this->instructions,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_required' => $this->is_required,

            'question_count' => $this->whenCounted('questions'),
            'attempt_count' => $this->whenCounted('attempts'),

            'is_in_use' => $inUse,
            'structure_locked' => $inUse,

            'sections' => AdminSectionResource::collection($this->whenLoaded('sections')),
            'questions' => AdminQuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
