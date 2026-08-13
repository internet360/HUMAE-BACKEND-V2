<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Models\InterviewRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InterviewRequest
 *
 * Una solicitud de entrevistas, vista por la empresa que la envió.
 *
 * Los perfiles siguen saliendo anónimos incluso aquí, en la solicitud propia:
 * seleccionar a alguien no revela quién es. La identidad se abre cuando HUMAE
 * confirma la entrevista, y ni un momento antes — si el mero hecho de pedir
 * abriera el expediente, bastaría con señalar a media base para vaciarla.
 */
class InterviewRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state?->value,
            'state_label' => $this->state?->label(),

            'vacancy' => [
                'id' => $this->vacancy_id,
                'title' => $this->vacancy?->title,
                'code' => $this->vacancy?->code,
                'state' => $this->vacancy?->state?->value,
            ],

            'proposed_slots' => array_map(
                static fn ($slot) => $slot->toIso8601String(),
                $this->proposedSlots(),
            ),
            'timezone' => $this->timezone,
            'note' => $this->note,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            'candidates' => InterviewRequestCandidateResource::collection(
                $this->whenLoaded('candidates'),
            ),
        ];
    }
}
