<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Companies;

use App\Http\Resources\V1\Directory\AnonymousCandidateResource;
use App\Models\InterviewRequestCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InterviewRequestCandidate
 *
 * Un perfil dentro de una solicitud, con su desenlace.
 *
 * El perfil viaja envuelto en `AnonymousCandidateResource`, el mismo de la
 * navegación: reutilizarlo es lo que garantiza que la silueta sea idéntica
 * antes y después de seleccionar. Un recurso propio aquí acabaría divergiendo,
 * y la divergencia en esta dirección se llama fuga.
 *
 * `rejection_reason` sí se muestra: un veto sin motivo deja al cliente
 * rehaciendo la selección a ciegas.
 */
class InterviewRequestCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'state' => $this->state?->value,
            'state_label' => $this->state?->label(),
            'rejection_reason' => $this->rejection_reason,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'candidate' => AnonymousCandidateResource::make(
                $this->whenLoaded('candidateProfile'),
            ),
        ];
    }
}
