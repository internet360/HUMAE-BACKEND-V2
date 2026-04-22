<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Companies\CompanyResource;
use App\Models\Company;
use App\Models\CompanyMember;
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
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasAnyRole([UserRole::CompanyUser->value, UserRole::Admin->value])) {
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

        return $this->success(
            message: 'Empresa.',
            data: CompanyResource::make($company),
            meta: [
                'member_role' => $member?->role?->value,
                'can_edit' => $this->canEdit($user, $member),
            ],
        );
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasAnyRole([UserRole::CompanyUser->value, UserRole::Admin->value])) {
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

        $validated = $request->validate([
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'legal_name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'website' => ['sometimes', 'nullable', 'url', 'max:300'],
            'founded_year' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:2099'],
            'industry_id' => ['sometimes', 'nullable', 'integer', 'exists:industries,id'],
            'company_size_id' => ['sometimes', 'nullable', 'integer', 'exists:company_sizes,id'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'contact_position' => ['sometimes', 'nullable', 'string', 'max:200'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:300'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:15'],
            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'instagram_url' => ['sometimes', 'nullable', 'url', 'max:300'],
            'twitter_url' => ['sometimes', 'nullable', 'url', 'max:300'],
        ]);

        $company->update($validated);

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

    /**
     * @return array{0: Company|null, 1: CompanyMember|null}
     */
    private function resolveCompany(User $user): array
    {
        /** @var CompanyMember|null $member */
        $member = $user->companyMemberships()
            ->with('company')
            ->orderBy('id')
            ->first();

        return [$member?->company, $member];
    }

    private function canEdit(User $user, ?CompanyMember $member): bool
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
}
