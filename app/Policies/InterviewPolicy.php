<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentStage;
use App\Enums\UserRole;
use App\Models\Interview;
use App\Models\User;
use App\Models\VacancyAssignment;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for interviews.
 *
 * An interview hangs off a VacancyAssignment, so it inherits that assignment's
 * confidentiality. For a client company the boundary is
 * `AssignmentStage::visibleToCompany()`: `sourced` is the recruiter's internal
 * short list and `rejected` are discards from before the presentation, and
 * ARCHITECTURE.md §6 only grants the company "Ver candidatos asignados a
 * vacante — ✅ propia vacante", meaning the ones HUMAE chose to present.
 *
 * Gating on company membership alone was not enough: `vacancy_assignment_id` is
 * a caller-supplied integer, so a company could schedule an interview against
 * an unpresented assignment on her own vacancy and read the candidate's name
 * out of the response.
 */
class InterviewPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    /**
     * Read an interview. Recruiters always; the candidate it belongs to; a
     * company member, but only for a candidate already presented to her.
     */
    public function view(User $user, Interview $interview): Response
    {
        $assignment = $interview->assignment;

        if ($assignment === null) {
            return Response::deny('La entrevista no está vinculada a una asignación.');
        }

        if ($user->hasRole(UserRole::Recruiter->value)) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::Candidate->value)
            && $assignment->candidateProfile?->user_id === $user->id) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::CompanyUser->value)
            && $this->isPresentedTo($user, $assignment)) {
            return Response::allow();
        }

        return Response::deny('No tienes acceso a esta entrevista.');
    }

    /**
     * Accept the proposed slot. §6 — "Confirmar entrevista: Candidato ✅ (la
     * propia), Reclutador ✅, Empresa ✅ (propia vacante)". Same set as `view`,
     * but named for the act so the two can diverge without a rewrite.
     */
    public function confirm(User $user, Interview $interview): Response
    {
        return $this->view($user, $interview);
    }

    /**
     * Pick one of the two proposed slots: the candidate it belongs to, HUMAE
     * staff, or a decision maker of the company the candidate was presented to.
     */
    public function selectSlot(User $user, Interview $interview): Response
    {
        $assignment = $interview->assignment;

        if ($assignment === null) {
            return Response::deny('La entrevista no está vinculada a una asignación.');
        }

        if ($user->hasRole(UserRole::Recruiter->value)) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::Candidate->value)
            && $assignment->candidateProfile?->user_id === $user->id) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::CompanyUser->value)
            && $this->isPresentedTo($user, $assignment, decisionMakerOnly: true)) {
            return Response::allow();
        }

        return Response::deny('No puedes seleccionar el horario de esta entrevista.');
    }

    public function reschedule(User $user, Interview $interview): Response
    {
        $assignment = $interview->assignment;

        if ($assignment === null) {
            return Response::deny('La entrevista no está vinculada a una asignación.');
        }

        if ($user->hasRole(UserRole::Recruiter->value)) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::CompanyUser->value)
            && $this->isPresentedTo($user, $assignment, decisionMakerOnly: true)) {
            return Response::allow();
        }

        return Response::deny('No puedes reprogramar esta entrevista.');
    }

    /**
     * Call the interview off.
     *
     * §5.8 lists the route without a role, so the inference is "the parties to
     * the interview, plus HUMAE" — which includes the candidate. The ability
     * was written without the candidate branch and never invoked, so nobody
     * noticed: the controller was authorizing cancellations on `view`, which
     * does allow the candidate. Wiring the ability exposed the gap.
     */
    public function cancel(User $user, Interview $interview): Response
    {
        $assignment = $interview->assignment;

        if ($assignment === null) {
            return Response::deny('La entrevista no está vinculada a una asignación.');
        }

        if ($user->hasRole(UserRole::Recruiter->value)) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::Candidate->value)
            && $assignment->candidateProfile?->user_id === $user->id) {
            return Response::allow();
        }

        if ($user->hasRole(UserRole::CompanyUser->value)
            && $this->isPresentedTo($user, $assignment)) {
            return Response::allow();
        }

        return Response::deny('No puedes cancelar esta entrevista.');
    }

    /**
     * True when the assignment reached a stage HUMAE shares with the company
     * AND the user belongs to that company. Both halves are required: the stage
     * is the confidentiality gate, the membership is the tenancy gate.
     *
     * @param  bool  $decisionMakerOnly  restrict to owner/manager members.
     */
    private function isPresentedTo(
        User $user,
        VacancyAssignment $assignment,
        bool $decisionMakerOnly = false,
    ): bool {
        $stage = $assignment->stage;

        if ($stage === null || ! in_array($stage, AssignmentStage::visibleToCompany(), true)) {
            return false;
        }

        $company = $assignment->vacancy?->company;

        if ($company === null) {
            return false;
        }

        $members = $company->members()->where('user_id', $user->id);

        if ($decisionMakerOnly) {
            $members->whereIn('role', ['owner', 'manager']);
        }

        return $members->exists();
    }
}
