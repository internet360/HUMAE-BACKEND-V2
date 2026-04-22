<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogs\DegreeLevelRequest;
use App\Http\Resources\V1\Admin\Catalogs\DegreeLevelAdminResource;
use App\Models\DegreeLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * CRUD del catálogo de niveles académicos. Protegido por `catalogs.manage`.
 */
class DegreeLevelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $term = $request->string('q')->trim()->toString();

        $levels = DegreeLevel::query()
            ->when($term !== '', function ($query) use ($term): void {
                $pattern = '%'.$term.'%';
                $query->where(function ($inner) use ($pattern): void {
                    $inner->where('name', 'like', $pattern)
                        ->orWhere('code', 'like', $pattern);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(
            message: 'Niveles académicos del catálogo.',
            data: DegreeLevelAdminResource::collection($levels),
        );
    }

    public function store(DegreeLevelRequest $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $level = DegreeLevel::create($data);

        return $this->success(
            message: 'Nivel académico creado.',
            data: DegreeLevelAdminResource::make($level),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(DegreeLevelRequest $request, DegreeLevel $degreeLevel): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $degreeLevel->update($request->validated());

        return $this->success(
            message: 'Nivel académico actualizado.',
            data: DegreeLevelAdminResource::make($degreeLevel->fresh()),
        );
    }

    public function destroy(Request $request, DegreeLevel $degreeLevel): JsonResponse
    {
        $this->authorize('catalogs.manage');

        // degree_level_id en candidate_educations y vacancies usa nullOnDelete:
        // borrar es seguro, simplemente libera la referencia en esas filas.
        $degreeLevel->delete();

        return $this->success(
            message: 'Nivel académico eliminado.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
