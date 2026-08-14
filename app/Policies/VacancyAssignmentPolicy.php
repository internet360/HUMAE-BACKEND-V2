<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentStage;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;

/**
 * Authorization for the recruitment pipeline.
 *
 * ARCHITECTURE.md §5.7 lists every pipeline endpoint as recruiter / admin, with
 * exactly one exception: `PATCH /assignments/{id}/select-finalist`, which the
 * client company decides (§6, "Seleccionar candidato finalista: Empresa ✅").
 *
 * The abilities live here — and not on VacancyPolicy — because "may see this
 * vacancy" and "may operate its pipeline" are different abilities held by
 * different roles. A client company sees its own vacancy and reads its own
 * shortlist through the company endpoint
 * (`GET /me/company/vacancies/{id}/assignments`, stage-filtered); it never
 * operates the internal pipeline.
 */
class VacancyAssignmentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    /**
     * List the pipeline of a vacancy. Internal staff only.
     */
    public function viewAny(User $user, Vacancy $vacancy): bool
    {
        return $this->isInternalStaff($user);
    }

    /**
     * Assign a candidate to a vacancy. Internal staff only
     * (§6 — "Asignar candidatos a vacante: Empresa ❌").
     */
    public function create(User $user, Vacancy $vacancy): bool
    {
        return $this->isInternalStaff($user);
    }

    /**
     * Move a candidate between pipeline stages or edit pipeline metadata.
     * Internal staff only.
     */
    public function update(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user);
    }

    public function delete(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user);
    }

    /**
     * The only pipeline action a client company owns: picking the finalist
     * among the candidates HUMAE already presented to it.
     */
    public function selectFinalist(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user)
            || ($this->isVisibleToCompany($assignment)
                && $this->isCompanyDecisionMaker($user, $assignment));
    }

    /**
     * Capture the final confirmed salary of a placement.
     *
     * Internal staff only, and deliberately NOT open to the company even though
     * the company knows the number: it is the base of what HUMAE charges, so the
     * side that pays cannot be the side that writes it. The recruiter records
     * what was agreed and signs it with their user.
     */
    public function confirmFinalSalary(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user);
    }

    /**
     * Close the placement (→ hired).
     *
     * Both sides own this one — the checklist asks for it explicitly: "desde el
     * dashboard del empleador, o desde el panel del reclutador". The company
     * decides who it hires; HUMAE can also record it when the client confirms by
     * phone.
     *
     * Same visibility fence as `selectFinalist`: a company only closes on
     * candidates HUMAE actually presented to it.
     */
    public function hire(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user)
            || ($this->isVisibleToCompany($assignment)
                && $this->isCompanyDecisionMaker($user, $assignment));
    }

    /**
     * Propose an interview for an assignment.
     *
     * `vacancy_assignment_id` arrives in the request body, so the company can
     * name any row she likes. Membership over the vacancy is not enough: an
     * interview echoes the candidate back in its response, which would turn
     * this endpoint into a reader for the recruiter's internal short list. The
     * candidate must have been presented first (§6).
     */
    public function scheduleInterview(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user)
            || ($this->isVisibleToCompany($assignment)
                && $this->isCompanyMember($user, $assignment));
    }

    /**
     * Read the note thread of an assignment. A company member only reaches the
     * thread of a candidate that was actually presented to it, and the
     * controller still filters the thread down to `visibility=company`.
     */
    public function viewNotes(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user)
            || ($this->isVisibleToCompany($assignment)
                && $this->isCompanyMember($user, $assignment));
    }

    /**
     * Add a note. Companies may only add company-visible notes; internal notes
     * are staff-only (§6 — "Agregar notas internas: Empresa ❌"), which the
     * controller enforces by forcing the visibility.
     */
    public function createNote(User $user, VacancyAssignment $assignment): bool
    {
        return $this->viewNotes($user, $assignment);
    }

    /**
     * Read the psychometric results of the candidate on this assignment.
     *
     * Misma forma que `viewNotes()` y por la misma razón: la empresa sólo alcanza
     * al candidato que HUMAE le PRESENTÓ. `isVisibleToCompany()` es el candado —
     * sin él, la empresa leería el perfil psicométrico de la lista interna del
     * reclutador (`sourced`) y de sus descartes (`rejected`), que es
     * exactamente lo que §6 le cierra.
     *
     * Se ancla en la asignación y no en el candidato a propósito: la empresa no
     * tiene ninguna vía para nombrar un `candidate_profile_id` suelto, y así el
     * alcance queda atado a la vacante por la que lo conoció.
     */
    public function viewPsychometrics(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user)
            || ($this->isVisibleToCompany($assignment)
                && $this->isCompanyMember($user, $assignment));
    }

    /**
     * Read notes flagged `visibility=internal`. Staff only.
     */
    public function viewInternalNotes(User $user, VacancyAssignment $assignment): bool
    {
        return $this->isInternalStaff($user);
    }

    /**
     * HUMAE staff: recruiters and admins. Admins already short-circuit in
     * `before()`; the role is kept here so the helper is honest on its own.
     */
    private function isInternalStaff(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::Recruiter->value,
            UserRole::Admin->value,
        ]);
    }

    /**
     * Company members that may take decisions on the vacancy (owner/manager).
     */
    private function isCompanyDecisionMaker(User $user, VacancyAssignment $assignment): bool
    {
        if (! $user->hasRole(UserRole::CompanyUser->value)) {
            return false;
        }

        $company = $assignment->vacancy?->company;

        return $company !== null
            && $company->members()
                ->where('user_id', $user->id)
                ->whereIn('role', [
                    CompanyMemberRole::Owner->value,
                    CompanyMemberRole::Manager->value,
                ])
                ->exists();
    }

    /**
     * Any member of the company that owns the vacancy, whatever its role.
     */
    private function isCompanyMember(User $user, VacancyAssignment $assignment): bool
    {
        if (! $user->hasRole(UserRole::CompanyUser->value)) {
            return false;
        }

        $company = $assignment->vacancy?->company;

        return $company !== null
            && $company->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Assignments HUMAE already presented to the company. `sourced` and
     * `rejected` never leave the internal team, so neither does anything
     * hanging off them.
     */
    private function isVisibleToCompany(VacancyAssignment $assignment): bool
    {
        $stage = $assignment->stage;

        return $stage !== null
            && in_array($stage, AssignmentStage::visibleToCompany(), true);
    }
}
