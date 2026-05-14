<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\User;

class CandidateProfilePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::Recruiter->value,
            UserRole::CompanyUser->value,
            UserRole::Admin->value,
        ]);
    }

    public function view(User $user, CandidateProfile $profile): bool
    {
        if ($user->hasAnyRole([UserRole::Recruiter->value, UserRole::CompanyUser->value])) {
            return true;
        }

        return $profile->user_id === $user->id;
    }

    public function update(User $user, CandidateProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function delete(User $user, CandidateProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
