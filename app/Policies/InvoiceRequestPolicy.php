<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InvoiceRequest;
use App\Models\User;

/**
 * Only the owner reads their request; admins read everything. Ability names
 * must not collide with permission names (Spatie's Gate::before would
 * auto-approve them).
 */
class InvoiceRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    public function view(User $user, InvoiceRequest $request): bool
    {
        return $request->user_id === $user->id;
    }
}
