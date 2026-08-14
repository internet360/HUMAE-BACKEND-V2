<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Pipeline;

use App\Http\Resources\V1\Directory\DirectoryCandidateResource;
use App\Models\InterviewRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InterviewRequest
 *
 * Una solicitud de entrevistas, vista por HUMAE.
 *
 * Es el reverso exacto de `InterviewRequestResource`: allí los perfiles salen
 * anónimos porque los mira el cliente, aquí salen completos porque los mira
 * quien tiene que decidir si se presentan. Dos recursos separados y no uno con
 * condicionales — un `if ($user->isStaff())` dentro de un Resource es una fuga
 * esperando un refactor distraído.
 */
class StaffInterviewRequestResource extends JsonResource
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

            'company' => [
                'id' => $this->company_id,
                'legal_name' => $this->company?->legal_name,
                'trade_name' => $this->company?->trade_name,
            ],
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

            'requested_by_user_id' => $this->requested_by_user_id,
            'assigned_recruiter_id' => $this->assigned_recruiter_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            'candidates' => $this->whenLoaded('candidates', fn () => $this->candidates
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'state' => $item->state?->value,
                    'state_label' => $item->state?->label(),
                    'rejection_reason' => $item->rejection_reason,
                    'vacancy_assignment_id' => $item->vacancy_assignment_id,
                    'resolved_at' => $item->resolved_at?->toIso8601String(),
                    'candidate' => $item->candidateProfile !== null
                        ? DirectoryCandidateResource::make($item->candidateProfile)
                        : null,
                ])
                ->values()),
        ];
    }
}
