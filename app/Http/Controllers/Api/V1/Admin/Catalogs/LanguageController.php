<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogs\LanguageRequest;
use App\Http\Resources\V1\Admin\Catalogs\LanguageAdminResource;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * CRUD del catálogo de idiomas. Protegido por `catalogs.manage`.
 */
class LanguageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $term = $request->string('q')->trim()->toString();

        $languages = Language::query()
            ->when($term !== '', function ($query) use ($term): void {
                $pattern = '%'.$term.'%';
                $query->where(function ($inner) use ($pattern): void {
                    $inner->where('name', 'like', $pattern)
                        ->orWhere('native_name', 'like', $pattern)
                        ->orWhere('code', 'like', $pattern);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(
            message: 'Idiomas del catálogo.',
            data: LanguageAdminResource::collection($languages),
        );
    }

    public function store(LanguageRequest $request): JsonResponse
    {
        $this->authorize('catalogs.manage');

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $language = Language::create($data);

        return $this->success(
            message: 'Idioma creado.',
            data: LanguageAdminResource::make($language),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(LanguageRequest $request, Language $language): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $language->update($request->validated());

        return $this->success(
            message: 'Idioma actualizado.',
            data: LanguageAdminResource::make($language->fresh()),
        );
    }

    public function destroy(Request $request, Language $language): JsonResponse
    {
        $this->authorize('catalogs.manage');

        $inUse = DB::table('candidate_languages')->where('language_id', $language->id)->exists()
            || DB::table('vacancy_languages')->where('language_id', $language->id)->exists();

        if ($inUse) {
            return $this->error(
                message: 'No se puede borrar: hay candidatos o vacantes usando este idioma. Desactívalo en su lugar.',
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $language->delete();

        return $this->success(
            message: 'Idioma eliminado.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
