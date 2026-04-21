<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\VacancyRequest;
use App\Http\Resources\V1\Companies\VacancyResource;
use App\Models\User;
use App\Models\Vacancy;
use Cocur\Slugify\Slugify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Endpoints para usuarios tipo `company_user`. Solo operan sobre las vacantes
 * de las empresas a las que están vinculados vía company_members.
 */
class CompanyVacancyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasAnyRole([UserRole::CompanyUser->value, UserRole::Admin->value])) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        $companyIds = $user->companyMemberships()->pluck('company_id');

        $vacancies = Vacancy::query()
            ->whereIn('company_id', $companyIds)
            ->with('company')
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success(
            message: 'Vacantes de tu empresa.',
            data: VacancyResource::collection($vacancies),
            meta: [
                'pagination' => [
                    'current_page' => $vacancies->currentPage(),
                    'per_page' => $vacancies->perPage(),
                    'total' => $vacancies->total(),
                    'last_page' => $vacancies->lastPage(),
                ],
            ],
        );
    }

    public function store(VacancyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasRole(UserRole::CompanyUser->value) && ! $user->hasRole(UserRole::Admin->value)) {
            return $this->error('No tienes acceso a este recurso.', status: HttpStatus::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Verifica que el usuario sea miembro de la empresa pasada
        $isMember = $user->companyMemberships()
            ->where('company_id', (int) $data['company_id'])
            ->exists();

        if (! $isMember && ! $user->hasRole(UserRole::Admin->value)) {
            return $this->error(
                'No puedes crear vacantes para esta empresa.',
                status: HttpStatus::HTTP_FORBIDDEN,
            );
        }

        $slugify = new Slugify;
        $base = $slugify->slugify((string) $data['title']) ?: 'vacante';
        $slug = $base;
        $i = 1;
        while (Vacancy::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }

        $year = (int) now()->format('Y');
        $prefix = "HUM-{$year}-";
        $last = Vacancy::where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $next = 1;
        if ($last !== null) {
            $next = ((int) substr((string) $last, strlen($prefix))) + 1;
        }
        $code = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);

        $vacancy = Vacancy::create([
            ...$data,
            'created_by' => $user->id,
            'state' => VacancyState::Borrador->value,
            'slug' => $slug,
            'code' => $code,
        ]);
        $vacancy->load('company');

        return $this->success(
            message: 'Vacante creada en borrador. HUMAE la revisará.',
            data: VacancyResource::make($vacancy),
            status: HttpStatus::HTTP_CREATED,
        );
    }
}
