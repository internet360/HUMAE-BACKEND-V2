<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Companies\CompanyMemberResource;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Support\Tenancy\CompanyTenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Gestión del equipo de la propia empresa, visible para `company_user`.
 * Alcance: la primera empresa del usuario autenticado.
 * Autorización: owner escribe; cualquier miembro puede leer.
 */
class MyCompanyMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$user, $company, $selfMember] = $this->resolve($request);
        if ($company === null || $selfMember === null) {
            return $this->notLinked();
        }

        $members = $company->members()->with('user')->orderBy('id')->get();

        return $this->success(
            message: 'Miembros de tu empresa.',
            data: CompanyMemberResource::collection($members),
            meta: [
                'can_manage' => $this->canManage($user, $selfMember),
                'self_member_id' => $selfMember->id,
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        [$user, $company, $selfMember] = $this->resolve($request);
        if ($company === null || $selfMember === null) {
            return $this->notLinked();
        }
        if (! $this->canManage($user, $selfMember)) {
            return $this->forbiddenManage();
        }

        $roles = array_map(fn (CompanyMemberRole $r) => $r->value, CompanyMemberRole::cases());

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'role' => ['required', Rule::in($roles)],
            'job_title' => ['nullable', 'string', 'max:200'],
            'is_primary_contact' => ['sometimes', 'boolean'],
        ]);

        $target = User::where('email', $validated['email'])->first();
        if ($target === null) {
            return $this->error(
                'Ese correo no tiene una cuenta de empresa en HUMAE. Pide a tu contacto en HUMAE que la invite.',
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existing = $company->members()->where('user_id', $target->id)->first();
        if ($existing !== null) {
            return $this->error(
                'Ese usuario ya forma parte del equipo.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        // Naming somebody else's address is not that person's consent (F-13).
        // The endpoint used to attach any existing account and, worse, hand it
        // the Spatie `company_user` role if it did not have one — so a client
        // could conscript a candidate out of HUMAE's own talent base into its
        // team. Company accounts come from HUMAE (§6 «Registrarse — Empresa
        // cliente ❌ (invitación)»); this endpoint only wires up a colleague
        // HUMAE already provisioned, and never changes anybody's role.
        if (! $target->hasRole(UserRole::CompanyUser->value)) {
            return $this->error(
                'Esa cuenta no es de empresa. Pide a tu contacto en HUMAE que la invite a tu equipo.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        // Asked through CompanyTenancy, not through `$target->companyMemberships()`:
        // that relation runs under the caller's own tenancy scope, so it would
        // only ever see this company and always answer "no other company".
        if (app(CompanyTenancy::class)->companyIdsFor($target) !== []) {
            return $this->error(
                'Esa cuenta ya pertenece a otra empresa.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        if (! empty($validated['is_primary_contact'])) {
            $company->members()->update(['is_primary_contact' => false]);
        }

        /** @var CompanyMember $member */
        $member = $company->members()->create([
            'user_id' => $target->id,
            'role' => $validated['role'],
            'job_title' => $validated['job_title'] ?? null,
            'is_primary_contact' => (bool) ($validated['is_primary_contact'] ?? false),
            'accepted_at' => now(),
        ]);
        $member->load('user');

        return $this->success(
            message: 'Miembro agregado al equipo.',
            data: CompanyMemberResource::make($member),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(Request $request, CompanyMember $member): JsonResponse
    {
        [$user, $company, $selfMember] = $this->resolve($request);
        if ($company === null || $selfMember === null) {
            return $this->notLinked();
        }
        if (! $this->canManage($user, $selfMember)) {
            return $this->forbiddenManage();
        }
        if ($member->company_id !== $company->id) {
            return $this->error('Miembro no encontrado.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $roles = array_map(fn (CompanyMemberRole $r) => $r->value, CompanyMemberRole::cases());

        $validated = $request->validate([
            'role' => ['sometimes', Rule::in($roles)],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_primary_contact' => ['sometimes', 'boolean'],
        ]);

        // Evita dejar la empresa sin owner (bajar al único owner)
        if (
            isset($validated['role'])
            && $member->role === CompanyMemberRole::Owner
            && $validated['role'] !== CompanyMemberRole::Owner->value
        ) {
            $otherOwners = $company->members()
                ->where('role', CompanyMemberRole::Owner->value)
                ->where('id', '!=', $member->id)
                ->count();
            if ($otherOwners === 0) {
                return $this->error(
                    'No puedes dejar a la empresa sin un owner. Promueve a otro miembro primero.',
                    status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        if (! empty($validated['is_primary_contact'])) {
            $company->members()
                ->where('id', '!=', $member->id)
                ->update(['is_primary_contact' => false]);
        }

        $member->update($validated);
        $member->load('user');

        return $this->success(
            message: 'Miembro actualizado.',
            data: CompanyMemberResource::make($member->fresh(['user']) ?? $member),
        );
    }

    public function destroy(Request $request, CompanyMember $member): JsonResponse
    {
        [$user, $company, $selfMember] = $this->resolve($request);
        if ($company === null || $selfMember === null) {
            return $this->notLinked();
        }
        if (! $this->canManage($user, $selfMember)) {
            return $this->forbiddenManage();
        }
        if ($member->company_id !== $company->id) {
            return $this->error('Miembro no encontrado.', status: HttpStatus::HTTP_NOT_FOUND);
        }
        if ($member->id === $selfMember->id) {
            return $this->error(
                'No puedes removerte a ti mismo.',
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if ($member->role === CompanyMemberRole::Owner) {
            $otherOwners = $company->members()
                ->where('role', CompanyMemberRole::Owner->value)
                ->where('id', '!=', $member->id)
                ->count();
            if ($otherOwners === 0) {
                return $this->error(
                    'No puedes remover al único owner.',
                    status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $member->delete();

        return $this->success(message: 'Miembro removido.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    /**
     * @return array{0: User, 1: Company|null, 2: CompanyMember|null}
     */
    private function resolve(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        /** @var CompanyMember|null $member */
        $member = $user->companyMemberships()
            ->with('company')
            ->orderBy('id')
            ->first();

        return [$user, $member?->company, $member];
    }

    private function canManage(User $user, CompanyMember $member): bool
    {
        if ($user->hasRole(UserRole::Admin->value)) {
            return true;
        }

        return $member->role === CompanyMemberRole::Owner;
    }

    private function notLinked(): JsonResponse
    {
        return $this->error(
            'Tu cuenta no está vinculada a una empresa.',
            status: HttpStatus::HTTP_NOT_FOUND,
        );
    }

    private function forbiddenManage(): JsonResponse
    {
        return $this->error(
            'Solo el owner puede administrar el equipo.',
            status: HttpStatus::HTTP_FORBIDDEN,
        );
    }
}
