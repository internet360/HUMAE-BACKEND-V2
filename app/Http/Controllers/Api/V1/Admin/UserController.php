<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Admin\AdminUserResource;
use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class UserController extends Controller
{
    private const INVITATION_TTL_DAYS = 7;

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $roleFilter = $request->string('role')->toString();
        $statusFilter = $request->string('status')->toString();
        $query = $request->string('q')->toString();

        $users = User::query()
            ->with(['roles', 'companyMemberships.company'])
            ->when($query !== '', function ($q) use ($query): void {
                $like = '%'.$query.'%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('email', 'like', $like)
                        ->orWhere('name', 'like', $like);
                });
            })
            ->when($roleFilter !== '', function ($q) use ($roleFilter): void {
                $q->whereHas('roles', fn ($r) => $r->where('name', $roleFilter));
            })
            ->when($statusFilter !== '', function ($q) use ($statusFilter): void {
                if ($statusFilter === 'invited') {
                    $q->whereNotNull('invitation_token')
                        ->whereNull('invitation_accepted_at');
                } elseif ($statusFilter === 'active') {
                    $q->where(function ($inner): void {
                        $inner->whereNull('invitation_token')
                            ->orWhereNotNull('invitation_accepted_at');
                    });
                }
            })
            ->orderByDesc('id')
            ->paginate(20);

        return $this->success(
            message: 'Usuarios.',
            data: AdminUserResource::collection($users),
            meta: [
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ],
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $systemRoles = array_map(fn (UserRole $r) => $r->value, UserRole::cases());
        $memberRoles = array_map(
            fn (CompanyMemberRole $r) => $r->value,
            CompanyMemberRole::cases(),
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in($systemRoles)],
            'send_invite' => ['sometimes', 'boolean'],
            'company_id' => [
                'nullable',
                'integer',
                'exists:companies,id',
                Rule::requiredIf(
                    fn () => $request->input('role') === UserRole::CompanyUser->value,
                ),
            ],
            'company_member_role' => [
                'nullable',
                Rule::in($memberRoles),
                Rule::requiredIf(
                    fn () => $request->input('role') === UserRole::CompanyUser->value,
                ),
            ],
            'job_title' => ['nullable', 'string', 'max:200'],
        ]);

        $sendInvite = (bool) ($validated['send_invite'] ?? true);

        /** @var User $authUser */
        $authUser = $request->user();

        [$user, $plainToken] = DB::transaction(function () use ($validated, $sendInvite, $authUser) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make(Str::random(40)),
                'status' => $sendInvite ? 'invited' : 'active',
                'invited_by_user_id' => $authUser->id,
            ]);

            $user->assignRole($validated['role']);

            $plainToken = null;
            if ($sendInvite) {
                $plainToken = Str::random(64);
                $user->forceFill([
                    'invitation_token' => hash('sha256', $plainToken),
                    'invitation_expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
                    'invitation_accepted_at' => null,
                ])->save();
            } else {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if (
                $validated['role'] === UserRole::CompanyUser->value
                && ! empty($validated['company_id'])
            ) {
                $company = Company::findOrFail((int) $validated['company_id']);
                $company->members()->create([
                    'user_id' => $user->id,
                    'role' => $validated['company_member_role'] ?? CompanyMemberRole::Viewer->value,
                    'job_title' => $validated['job_title'] ?? null,
                    'is_primary_contact' => false,
                    'accepted_at' => $sendInvite ? null : now(),
                ]);
            }

            return [$user, $plainToken];
        });

        if ($sendInvite && $plainToken !== null) {
            $user->notify(new UserInvitationNotification(
                token: $plainToken,
                roleLabel: $this->roleLabel($validated['role']),
                inviterName: $authUser->name,
                companyName: $this->companyName($validated),
            ));
        }

        $user->load(['roles', 'companyMemberships.company']);

        return $this->success(
            message: $sendInvite
                ? 'Usuario creado. Le enviamos un correo con el enlace para activar su cuenta.'
                : 'Usuario creado como activo.',
            data: AdminUserResource::make($user),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        if ($user->invitation_accepted_at !== null) {
            return $this->error(
                'El usuario ya activó su cuenta.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $plainToken = Str::random(64);
        $user->forceFill([
            'invitation_token' => hash('sha256', $plainToken),
            'invitation_expires_at' => now()->addDays(self::INVITATION_TTL_DAYS),
            'status' => 'invited',
        ])->save();

        /** @var Role|null $firstRole */
        $firstRole = $user->roles->first();
        $roleName = $firstRole !== null ? $firstRole->name : UserRole::Candidate->value;
        $company = $user->companyMemberships()->with('company')->first()?->company?->legal_name;

        /** @var User $authUser */
        $authUser = $request->user();

        $user->notify(new UserInvitationNotification(
            token: $plainToken,
            roleLabel: $this->roleLabel($roleName),
            inviterName: $authUser->name,
            companyName: $company,
        ));

        return $this->success(message: 'Invitación reenviada.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        /** @var User $authUser */
        $authUser = $request->user();
        if ($user->id === $authUser->id) {
            return $this->error(
                'No puedes eliminar tu propia cuenta.',
                status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user->delete();

        return $this->success(message: 'Usuario eliminado.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    private function ensureAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasRole(UserRole::Admin->value)) {
            abort(HttpStatus::HTTP_FORBIDDEN, 'Solo admin.');
        }
    }

    private function roleLabel(string $value): string
    {
        return match ($value) {
            UserRole::Admin->value => 'Administrador',
            UserRole::Recruiter->value => 'Reclutador',
            UserRole::CompanyUser->value => 'Usuario de empresa',
            UserRole::Candidate->value => 'Candidato',
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function companyName(array $validated): ?string
    {
        if (empty($validated['company_id'])) {
            return null;
        }

        /** @var Company|null $company */
        $company = Company::find((int) $validated['company_id']);

        return $company?->legal_name;
    }
}
