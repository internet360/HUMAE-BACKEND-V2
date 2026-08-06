<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Concerns\ResolvesMyCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\CompanyRequest;
use App\Http\Resources\V1\Companies\CompanyResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Endpoints para que un `company_user` vea y edite los datos de su empresa.
 * Alcance: la primera empresa a la que está vinculado vía `company_members`.
 */
class MyCompanyController extends Controller
{
    use ResolvesMyCompany;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayActAsCompany($user)) {
            return $this->error(
                'No tienes acceso a este recurso.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        // `latestContract` viaja aquí para que el gate de firma del frontend
        // resuelva con este mismo request en vez de pedir /contract aparte.
        [$company, $member] = $this->resolveCompany($user, ['latestContract']);

        if ($company === null) {
            return $this->error(
                'Tu cuenta no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        return $this->success(
            message: 'Empresa.',
            data: CompanyResource::make($company),
            meta: [
                'member_role' => $member?->role?->value,
                'can_edit' => $this->canEdit($user, $member),
            ],
        );
    }

    /**
     * Edit the caller's own company.
     *
     * Takes the shared `CompanyRequest`: which fields the role may submit is
     * decided there, once, for this endpoint and for HUMAE's registry. The
     * duplicated 20-line whitelist this replaced is what let the two surfaces
     * disagree about the same user.
     */
    public function update(CompanyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->mayActAsCompany($user)) {
            return $this->error(
                'No tienes acceso a este recurso.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        [$company, $member] = $this->resolveCompany($user);

        if ($company === null) {
            return $this->error(
                'Tu cuenta no está vinculada a una empresa.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        if (! $this->canEdit($user, $member)) {
            return $this->error(
                'Solo owner o manager pueden editar datos de la empresa.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        $company->update($request->validated());

        $fresh = $company->fresh() ?? $company;

        return $this->success(
            message: 'Datos de la empresa actualizados.',
            data: CompanyResource::make($fresh),
            meta: [
                'member_role' => $member?->role?->value,
                'can_edit' => true,
            ],
        );
    }
}
