<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Interview;
use App\Models\User;
use App\Models\VacancyAssignment;

class InterviewPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    public function view(User $user, Interview $interview): bool
    {
        $assignment = $interview->assignment;

        if ($assignment === null) {
            return false;
        }

        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        if ($user->hasRole(UserRole::Candidate->value)) {
            return $assignment->candidateProfile?->user_id === $user->id;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            return $assignment->vacancy?->company
                ?->members()
                ->where('user_id', $user->id)
                ->exists() ?? false;
        }

        return false;
    }

    public function schedule(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::Recruiter->value,
            UserRole::CompanyUser->value,
        ]);
    }

    public function scheduleForAssignment(User $user, VacancyAssignment $assignment): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            return $assignment->vacancy?->company
                ?->members()
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager'])
                ->exists() ?? false;
        }

        return false;
    }

    public function confirm(User $user, Interview $interview): bool
    {
        return $this->view($user, $interview);
    }

    public function reschedule(User $user, Interview $interview): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            return $interview->assignment?->vacancy?->company
                ?->members()
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager'])
                ->exists() ?? false;
        }

        return $this->view($user, $interview);
    }

    public function cancel(User $user, Interview $interview): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        if ($user->hasRole(UserRole::CompanyUser->value)) {
            return $interview->assignment?->vacancy?->company
                ?->members()
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'manager'])
                ->exists() ?? false;
        }

        return false;
    }
}
