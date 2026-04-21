<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AttachMemberRequest;
use App\Http\Resources\V1\Companies\CompanyMemberResource;
use App\Models\Company;
use App\Models\CompanyMember;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class CompanyMemberController extends Controller
{
    public function index(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->load(['members.user']);

        return $this->success(
            message: 'Miembros de la empresa.',
            data: CompanyMemberResource::collection($company->members),
        );
    }

    public function store(AttachMemberRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var CompanyMember $member */
        $member = $company->members()->updateOrCreate(
            ['user_id' => (int) $data['user_id']],
            [
                'role' => $data['role'],
                'job_title' => $data['job_title'] ?? null,
                'is_primary_contact' => (bool) ($data['is_primary_contact'] ?? false),
                'accepted_at' => now(),
            ],
        );

        $member->load('user');

        return $this->success(
            message: 'Miembro agregado.',
            data: CompanyMemberResource::make($member),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function destroy(Company $company, int $userId): JsonResponse
    {
        $this->authorize('update', $company);

        $company->members()->where('user_id', $userId)->delete();

        return $this->success(
            message: 'Miembro eliminado.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
