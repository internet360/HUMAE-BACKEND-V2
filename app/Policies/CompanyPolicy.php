<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * Authorization over the client company registry.
 *
 * `/companies/*` is HUMAE's registry of its clients, not a client's view of
 * itself. ARCHITECTURE.md §5.6 lists every route in it — list, read, create,
 * update, delete, and the member roster — as "admin / recruiter". The rows it
 * returns carry `rfc`, the billing contact, the address, `account_manager_id`
 * and `internal_notes`: one client reading them is one client reading HUMAE's
 * other clients.
 *
 * That is exactly what happened. `viewAny`, `view` and `update` used to admit
 * `company_user`, so any client user enumerated the whole roster (F-01), read
 * a company record through the staff endpoint (F-15), wrote fields its own
 * endpoint deliberately excludes (F-06), and reached the member management
 * routes, which authorize on those same two abilities (F-07).
 *
 * A client company sees and edits itself through `/me/company`, scoped to its
 * own membership, with the fields §6 grants it ("Ver/editar su propio perfil —
 * Empresa cliente ✅ (propia)").
 */
class CompanyPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    public function delete(User $user, Company $company): bool
    {
        return false; // Solo admin vía `before`.
    }
}
