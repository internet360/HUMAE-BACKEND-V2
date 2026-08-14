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

    /**
     * Leer el historial de contratos de una empresa: qué firmó, qué se le anuló
     * y qué le falta firmar.
     *
     * Va con el mismo alcance que `view` porque contesta la misma pregunta de
     * negocio —«¿en qué estado está este cliente?»— y el reclutador es quien la
     * hace todo el día: es él quien pone honorarios propios en una vacante y
     * quien necesita saber si esa adenda ya se firmó antes de facturarla.
     */
    public function viewContracts(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    /**
     * Abrir la identificación oficial y la selfie de quien firmó.
     *
     * Separada de `viewContracts` a propósito, aunque hoy devuelvan lo mismo.
     * Son datos personales sensibles y la decisión de quién los ve es de
     * negocio, no de arquitectura: el día que se restrinja a admin, se borra el
     * cuerpo de este método y `before()` hace el resto — sin tocar el
     * controller, sin tocar las rutas y sin dejar un segundo camino abierto.
     */
    public function viewContractEvidence(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }
}
