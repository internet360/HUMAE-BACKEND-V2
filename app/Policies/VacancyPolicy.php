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
        return $user->hasRole(UserRole::Recruiter->value)
            && $vacancy->assigned_recruiter_id === $user->id;
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
