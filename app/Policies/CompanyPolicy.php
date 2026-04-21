<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Recruiter->value, UserRole::CompanyUser->value]);
    }

    public function view(User $user, Company $company): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $company->members()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function update(User $user, Company $company): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $company->members()
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'manager'])
            ->exists();
    }

    public function delete(User $user, Company $company): bool
    {
        return false; // Solo admin vía `before`.
    }
}
