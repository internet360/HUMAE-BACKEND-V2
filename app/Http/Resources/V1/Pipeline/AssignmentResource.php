<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Pipeline;

use App\Enums\AssignmentStage;
use App\Enums\UserRole;
use App\Models\VacancyAssignment;
use App\Services\AssignmentStageMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Internal pipeline view. Every route that returns it is gated to
 * recruiter/admin except `PATCH /assignments/{id}/select-finalist`, the one
 * pipeline action ARCHITECTURE.md §6 hands to the client company
 * ("Seleccionar candidato finalista — Empresa cliente: ✅ decide").
 *
 * `recruiter_notes` and `rejection_reason` are HUMAE's own assessment of the
 * candidate, so they are gated on role here — §6 keeps internal notes away from
 * the company ("Agregar notas internas — Empresa cliente: ❌") and reading them
 * is worse than writing them. The sibling CompanyAssignmentResource omits them
 * for the same reason; this class is reachable by a company through exactly one
 * door, and that door was open.
 *
 * @mixin VacancyAssignment
 */
class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stage = $this->stage ?? AssignmentStage::Sourced;
        $profile = $this->candidateProfile;
        $isInternalStaff = $this->isInternalStaff($request);

        return [
            'id' => $this->id,
            'vacancy_id' => $this->vacancy_id,
            'candidate_profile_id' => $this->candidate_profile_id,
            'assigned_by' => $this->assigned_by,
            'stage' => $stage->value,
            'priority' => $this->priority?->value,
            'score' => $this->score,
            'recruiter_notes' => $this->when($isInternalStaff, fn () => $this->recruiter_notes),
            'rejection_reason' => $this->when($isInternalStaff, fn () => $this->rejection_reason),
            'allowed_transitions' => AssignmentStageMachine::allowedValuesFrom($stage),
            'candidate' => $profile !== null ? [
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'headline' => $profile->headline,
                'years_of_experience' => $profile->years_of_experience,
                'avatar_url' => $profile->user?->avatar_url,
            ] : null,
            'presented_at' => $this->presented_at?->toIso8601String(),
            'interviewed_at' => $this->interviewed_at?->toIso8601String(),
            'offer_sent_at' => $this->offer_sent_at?->toIso8601String(),
            'hired_at' => $this->hired_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'notes' => AssignmentNoteResource::collection(
                $this->whenLoaded('notes'),
            ),
        ];
    }

    private function isInternalStaff(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole([
            UserRole::Recruiter->value,
            UserRole::Admin->value,
        ]);
    }
}
