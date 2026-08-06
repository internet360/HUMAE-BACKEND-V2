<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\PositionCatalogRequest;
use App\Models\DegreeLevel;
use App\Models\FunctionalArea;
use App\Models\Language;
use App\Models\Position;
use App\Models\Skill;
use App\Models\VacancyType;
use Illuminate\Http\JsonResponse;

/**
 * Expone catálogos maestros (skills, languages, degree_levels, ...)
 * para pickers del frontend. Sólo lectura; los catálogos se
 * administran vía seeders + panel admin (fase 2).
 */
class CatalogController extends Controller
{
    public function skills(): JsonResponse
    {
        $skills = Skill::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category']);

        return $this->success(message: 'Catálogo de habilidades.', data: $skills);
    }

    public function languages(): JsonResponse
    {
        $languages = Language::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'native_name']);

        return $this->success(message: 'Catálogo de idiomas.', data: $languages);
    }

    public function degreeLevels(): JsonResponse
    {
        $levels = DegreeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return $this->success(message: 'Catálogo de niveles académicos.', data: $levels);
    }

    public function functionalAreas(): JsonResponse
    {
        $areas = FunctionalArea::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return $this->success(message: 'Catálogo de áreas funcionales.', data: $areas);
    }

    /**
     * Catálogo de puestos estandarizados. Cada puesto trae su
     * `functional_area_id` para que el frontend arme el selector en cascada
     * (Área → Puesto) con una sola petición, sin ir al servidor por cada
     * cambio de área. El filtro por área es opcional.
     */
    public function positions(PositionCatalogRequest $request): JsonResponse
    {
        $areaId = $request->integer('functional_area_id');

        $positions = Position::query()
            ->where('is_active', true)
            ->when($areaId > 0, fn ($q) => $q->where('functional_area_id', $areaId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'functional_area_id']);

        return $this->success(message: 'Catálogo de puestos.', data: $positions);
    }

    public function vacancyTypes(): JsonResponse
    {
        $types = VacancyType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return $this->success(message: 'Catálogo de tipos de jornada.', data: $types);
    }
}
