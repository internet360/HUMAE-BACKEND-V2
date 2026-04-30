<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogs\FunctionalAreaRequest;
use App\Http\Resources\V1\Admin\Catalogs\FunctionalAreaAdminResource;
use App\Models\FunctionalArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * CRUD del catálogo de áreas funcionales (departamentos / áreas de interés
 * laboral del candidato y de la vacante). Protegido por `catalogs.manage`.
 *
 * Las FKs `functional_area_id` en candidate_profiles, candidate_experiences
 * y vacancies usan nullOnDelete, así que borrar es seguro: las filas
 * referenciadas simplemente quedan con NULL.
 */
class FunctionalAreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $term = $request->string('q')->trim()->toString();

        $areas = FunctionalArea::query()
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
            message: 'Áreas funcionales del catálogo.',
            data: FunctionalAreaAdminResource::collection($areas),
        );
    }

    public function store(FunctionalAreaRequest $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $area = FunctionalArea::create($data);

        return $this->success(
            message: 'Área funcional creada.',
            data: FunctionalAreaAdminResource::make($area),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(FunctionalAreaRequest $request, FunctionalArea $functionalArea): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $functionalArea->update($request->validated());

        return $this->success(
            message: 'Área funcional actualizada.',
            data: FunctionalAreaAdminResource::make($functionalArea->fresh()),
        );
    }

    public function destroy(Request $request, FunctionalArea $functionalArea): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $functionalArea->delete();

        return $this->success(
            message: 'Área funcional eliminada.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
