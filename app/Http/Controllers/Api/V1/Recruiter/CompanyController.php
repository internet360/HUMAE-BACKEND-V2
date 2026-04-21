<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Recruiter;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\CompanyRequest;
use App\Http\Resources\V1\Companies\CompanyResource;
use App\Models\Company;
use Cocur\Slugify\Slugify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $companies = Company::query()
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where(function ($inner) use ($request): void {
                    $term = '%'.$request->string('q').'%';
                    $inner->where('legal_name', 'like', $term)
                        ->orWhere('trade_name', 'like', $term)
                        ->orWhere('slug', 'like', $term);
                }),
            )
            ->orderBy('legal_name')
            ->paginate(20);

        return $this->success(
            message: 'Empresas.',
            data: CompanyResource::collection($companies),
            meta: [
                'pagination' => [
                    'current_page' => $companies->currentPage(),
                    'per_page' => $companies->perPage(),
                    'total' => $companies->total(),
                    'last_page' => $companies->lastPage(),
                ],
            ],
        );
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        if (empty($data['slug'])) {
            $slugify = new Slugify;
            $data['slug'] = $this->uniqueSlug(
                $slugify->slugify((string) ($data['trade_name'] ?? $data['legal_name'] ?? 'empresa')),
            );
        }

        $company = Company::create($data);

        return $this->success(
            message: 'Empresa creada.',
            data: CompanyResource::make($company),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $company->load(['members.user']);

        return $this->success(
            message: 'Empresa.',
            data: CompanyResource::make($company),
        );
    }

    public function update(CompanyRequest $request, Company $company): JsonResponse
    {
        $this->authorize('update', $company);

        $company->update($request->validated());

        return $this->success(
            message: 'Empresa actualizada.',
            data: CompanyResource::make($company->fresh()),
        );
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $company->delete();

        return $this->success(message: 'Empresa archivada.', status: HttpStatus::HTTP_NO_CONTENT);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 1;
        while (Company::where('slug', $slug)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
