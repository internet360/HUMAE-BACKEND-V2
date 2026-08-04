<?php

declare(strict_types=1);

namespace App\Policies;

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

    public function update(User $user, Vacancy $vacancy): bool
    {
        // Los reclutadores HUMAE son staff interno — pueden editar cualquier vacante.
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            $company = $vacancy->company;

            return $company !== null
                && $company->members()
                    ->where('user_id', $user->id)
                    ->whereIn('role', ['owner', 'manager'])
                    ->exists();
        }

        return false;
    }

    public function publish(User $user, Vacancy $vacancy): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return $vacancy->assigned_recruiter_id === $user->id;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            $company = $vacancy->company;

            return $company !== null
                && $company->members()
                    ->where('user_id', $user->id)
                    ->whereIn('role', ['owner', 'manager'])
                    ->exists();
        }

        return false;
    }

    public function close(User $user, Vacancy $vacancy): bool
    {
        return $this->update($user, $vacancy);
    }

    public function delete(User $user, Vacancy $vacancy): bool
    {
        return false; // Solo admin vía `before`.
    }
}
