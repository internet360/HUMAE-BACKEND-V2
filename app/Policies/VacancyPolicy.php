<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Vacancy;

class VacancyPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Recruiter->value, UserRole::CompanyUser->value]);
    }

    public function view(User $user, Vacancy $vacancy): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            $company = $vacancy->company;

            return $company !== null
                && $company->members()->where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * Read the matching engine's suggestions for a vacancy.
     *
     * The payload is a ranked slice of the talent base: candidates HUMAE has
     * NOT presented, with name, headline, seniority and functional areas. That
     * is the candidate directory reached from a different door, and
     * ARCHITECTURE.md §6 closes the directory to the client company
     * ("Ver directorio de candidatos — Empresa cliente: ❌"). Sourcing is
     * HUMAE's curation step (§1), so this is internal staff only — owning the
     * vacancy grants nothing here.
     */
    public function viewSuggestedCandidates(User $user, Vacancy $vacancy): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Recruiter->value, UserRole::CompanyUser->value]);
    }

    /**
     * Edit the content of a vacancy: title, description, requirements.
     *
     * Strictly "edit". Moving the vacancy through its state machine is a
     * different question with a different answer per target state — see
     * `publish`, `close`, `cancel` and `advance`.
     */
    public function update(User $user, Vacancy $vacancy): bool
    {
        // Los reclutadores HUMAE son staff interno — pueden editar cualquier vacante.
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $this->isDecisionMaker($user, $vacancy);
    }

    /**
     * Approve a filed request and put it live (borrador → activa).
     *
     * HUMAE only. §6 — "Aprobar / activar vacante: Reclutador ✅, Empresa
     * cliente ❌". The company files the request; HUMAE decides it is ready to
     * go out. §6 attaches no condition about which recruiter, so any recruiter
     * may approve — the previous `assigned_recruiter_id` check invented a rule
     * the document does not state, and the same recruiter could edit the row
     * anyway.
     */
    public function publish(User $user, Vacancy $vacancy): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * File the vacancy as a request for interviews (→ solicitada).
     *
     * The employer flow: the client browsed the anonymous preview, picked whom
     * it wants to meet and files the request. This is the client's own decision,
     * so a decision maker of the owning company drives it — HUMAE can too, for
     * a request filed by phone.
     *
     * Its own ability rather than `advance`: `advance` is HUMAE reporting
     * progress on its mandate (§5.7), and this is the client asking for
     * something. Same distinction that keeps `publish` and `close` apart.
     */
    public function submit(User $user, Vacancy $vacancy): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $this->isDecisionMaker($user, $vacancy);
    }

    /**
     * Mark the vacancy filled (→ cubierta).
     *
     * §6 — "Marcar vacante como cubierta: Reclutador ✅ (confirma), Empresa
     * cliente ✅ (propone)". The only transition both sides own.
     */
    public function close(User $user, Vacancy $vacancy): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $this->isDecisionMaker($user, $vacancy);
    }

    /**
     * Call the search off (→ cancelada).
     *
     * §6 has no row for it. Keeps the behaviour both endpoints already had:
     * HUMAE, or a decision maker of the owning company.
     */
    public function cancel(User $user, Vacancy $vacancy): bool
    {
        return $this->close($user, $vacancy);
    }

    /**
     * Move the vacancy through the internal search states — `en_busqueda`,
     * `con_candidatos_asignados`, `entrevistas_en_curso`,
     * `finalista_seleccionado`.
     *
     * These describe HUMAE's own progress on the mandate (§5.7), not a decision
     * of the client. Staff only.
     */
    public function advance(User $user, Vacancy $vacancy): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function delete(User $user, Vacancy $vacancy): bool
    {
        return false; // Solo admin vía `before`.
    }

    /**
     * A member of the owning company allowed to decide for it.
     */
    private function isDecisionMaker(User $user, Vacancy $vacancy): bool
    {
        if (! $user->hasRole(UserRole::CompanyUser->value)) {
            return false;
        }

        $company = $vacancy->company;

        return $company !== null
            && $company->members()
                ->where('user_id', $user->id)
                ->whereIn('role', [
                    CompanyMemberRole::Owner->value,
                    CompanyMemberRole::Manager->value,
                ])
                ->exists();
    }
}
