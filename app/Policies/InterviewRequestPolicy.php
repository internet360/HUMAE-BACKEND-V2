<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\InterviewRequest;
use App\Models\User;

/**
 * Autorización sobre las solicitudes de entrevistas del empleador.
 *
 * La pertenencia a la empresa la resuelve además el scope de tenancy
 * (`BelongsToCompany`), así que una solicitud de otra empresa normalmente ni
 * llega a esta policy: la consulta no la devuelve. Estas reglas son el segundo
 * candado, para los caminos que escapan el scope a propósito y para que la
 * intención quede escrita donde se lee.
 */
class InterviewRequestPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::Admin->value) ? true : null;
    }

    /**
     * Listar las solicitudes propias. HUMAE ve todas desde su propio panel.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            UserRole::CompanyUser->value,
            UserRole::Recruiter->value,
        ]);
    }

    public function view(User $user, InterviewRequest $request): bool
    {
        if ($user->hasRole(UserRole::Recruiter->value)) {
            return true;
        }

        return $this->belongsToCompany($user, $request);
    }

    /**
     * Enviar una solicitud.
     *
     * Sólo quien decide por la empresa. Un miembro `viewer` mira el proceso
     * pero no compromete a su compañía a entrevistar a nadie — es la misma
     * frontera que separa consultar de operar en el resto del panel.
     */
    public function create(User $user): bool
    {
        if (! $user->hasRole(UserRole::CompanyUser->value)) {
            return false;
        }

        return $user->companyMemberships()
            ->whereIn('role', [
                CompanyMemberRole::Owner->value,
                CompanyMemberRole::Manager->value,
            ])
            ->exists();
    }

    /**
     * Aceptar o vetar un perfil señalado.
     *
     * Sólo HUMAE. Es la decisión de curación —a quién presentamos y a quién
     * no— y es exactamente lo que el cliente paga: si la empresa pudiera
     * resolverla, la solicitud se autoaprobaría y el filtro dejaría de existir.
     */
    public function resolve(User $user, InterviewRequest $request): bool
    {
        return $user->hasRole(UserRole::Recruiter->value);
    }

    private function belongsToCompany(User $user, InterviewRequest $request): bool
    {
        return $user->companyMemberships()
            ->where('company_id', $request->company_id)
            ->exists();
    }
}
