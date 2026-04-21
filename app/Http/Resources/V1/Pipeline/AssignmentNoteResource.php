<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Pipeline;

use App\Models\VacancyAssignmentNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VacancyAssignmentNote
 */
class AssignmentNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vacancy_assignment_id' => $this->vacancy_assignment_id,
            'author_id' => $this->author_id,
            'author' => [
                'id' => $this->author?->id,
                'name' => $this->author?->name,
            ],
            'visibility' => $this->visibility,
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
