<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics;

use App\Http\Controllers\Api\V1\Admin\Psychometrics\Concerns\GuardsFrozenStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Psychometrics\SectionRequest;
use App\Http\Resources\V1\Admin\Psychometrics\AdminSectionResource;
use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use App\Services\PsychometricAuthoringService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class SectionController extends Controller
{
    use GuardsFrozenStructure;

    public function __construct(
        private readonly PsychometricAuthoringService $authoring,
    ) {}

    public function index(PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $sections = $test->sections()->orderBy('sort_order')->get();

        return $this->success(
            message: 'Secciones de la prueba.',
            data: AdminSectionResource::collection($sections),
        );
    }

    public function store(SectionRequest $request, PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $test)) {
            return $refusal;
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $section = $test->sections()->create($data);

        return $this->success(
            message: 'Sección creada.',
            data: AdminSectionResource::make($section),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(SectionRequest $request, PsychometricTestSection $section): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $section->test)) {
            return $refusal;
        }

        $section->update($request->validated());

        return $this->success(
            message: 'Sección actualizada.',
            data: AdminSectionResource::make($section->fresh() ?? $section),
        );
    }

    public function destroy(PsychometricTestSection $section): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $section->test)) {
            return $refusal;
        }

        // Ojo: la FK de `psychometric_questions.psychometric_test_section_id` es
        // cascadeOnDelete, así que borrar la sección se lleva sus preguntas. Sólo
        // es seguro porque la prueba todavía no tiene intentos.
        $section->delete();

        return $this->success(
            message: 'Sección eliminada.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
