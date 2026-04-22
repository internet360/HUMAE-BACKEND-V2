<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\DegreeLevel;
use App\Models\Language;
use App\Models\Skill;
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
}
