<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;

/**
 * "¿Cuál es *mi* empresa, y qué puedo hacer en ella?" — resuelto en un solo lugar
 * para todo lo que cuelga de `/me/company`.
 *
 * Vive en `Http\Concerns` y no bajo un controller porque lo usan los dos lados:
 * el controller para resolver la empresa, y los Form Requests para autorizar
 * antes de validar.
 *
 * Estaba privado en MyCompanyController y a punto de copiarse a un tercer
 * consumidor. La misma omisión repetida es de dónde salieron seis de los
 * dieciséis hallazgos de auditoría (ver CompanyTenancy), así que la derivación
 * se comparte en vez de reescribirse.
 */
trait ResolvesMyCompany
{
    /**
     * La primera empresa a la que el usuario está vinculado vía `company_members`.
     *
     * @param  list<string>  $with  relaciones a precargar en la empresa
     * @return array{0: Company|null, 1: CompanyMember|null}
     */
    protected function resolveCompany(User $user, array $with = []): array
    {
        /** @var CompanyMember|null $member */
        $member = $user->companyMemberships()
            ->with(['company' => static function ($query) use ($with): void {
                if ($with !== []) {
                    $query->with($with);
                }
            }])
            ->orderBy('id')
            ->first();

        return [$member?->company, $member];
    }

    /**
     * Owner y manager administran; viewer sólo lee. Un admin de HUMAE puede todo.
     */
    protected function canEdit(User $user, ?CompanyMember $member): bool
    {
        if ($user->hasRole(UserRole::Admin->value)) {
            return true;
        }

        return $member !== null
            && in_array(
                $member->role,
                [CompanyMemberRole::Owner, CompanyMemberRole::Manager],
                true,
            );
    }

    /**
     * Sólo `company_user` y `admin` alcanzan estos endpoints. Un candidato o un
     * reclutador no tienen "su" empresa que administrar.
     */
    protected function mayActAsCompany(User $user): bool
    {
        return $user->hasAnyRole([UserRole::CompanyUser->value, UserRole::Admin->value]);
    }
}
