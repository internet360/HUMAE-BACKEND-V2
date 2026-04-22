<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogs\SkillRequest;
use App\Http\Resources\V1\Admin\Catalogs\SkillAdminResource;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * CRUD del catálogo de habilidades. Protegido por el permiso
 * Spatie `catalogs.manage` (asignado al rol admin en el seeder).
 *
 * A diferencia del endpoint público (/api/v1/catalogs/skills) este
 * index incluye habilidades desactivadas para que el admin pueda
 * reactivarlas.
 */
class SkillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $term = $request->string('q')->trim()->toString();

        $skills = Skill::query()
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
            message: 'Habilidades del catálogo.',
            data: SkillAdminResource::collection($skills),
        );
    }

    public function store(SkillRequest $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $skill = Skill::create($data);

        return $this->success(
            message: 'Habilidad creada.',
            data: SkillAdminResource::make($skill),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(SkillRequest $request, Skill $skill): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $skill->update($request->validated());

        return $this->success(
            message: 'Habilidad actualizada.',
            data: SkillAdminResource::make($skill->fresh()),
        );
    }

    public function destroy(Request $request, Skill $skill): JsonResponse
    {
        $this->authorize('catalogs.manage');

        // Pre-check explícito en vez de atrapar FK exception: candidate_skills
        // y vacancy_skills usan restrictOnDelete. Sugerimos desactivar para
        // no romper perfiles o requisitos de vacantes ya guardados.
        $inUse = DB::table('candidate_skills')->where('skill_id', $skill->id)->exists()
            || DB::table('vacancy_skills')->where('skill_id', $skill->id)->exists();

        if ($inUse) {
            return $this->error(
                message: 'No se puede borrar: hay candidatos o vacantes usando esta habilidad. Desactívala en su lugar.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $skill->delete();

        return $this->success(
            message: 'Habilidad eliminada.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
