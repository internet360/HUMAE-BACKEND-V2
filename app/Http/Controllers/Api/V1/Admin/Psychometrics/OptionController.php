<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics;

use App\Http\Controllers\Api\V1\Admin\Psychometrics\Concerns\GuardsFrozenStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Psychometrics\OptionRequest;
use App\Http\Resources\V1\Admin\Psychometrics\AdminOptionResource;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Services\PsychometricAuthoringService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Opciones de respuesta. Acá se define el `score` de cada opción, que es la
 * fuente de verdad del puntaje — la única, desde que el candidato dejó de poder
 * mandarlo (ver `SavePsychometricAnswersRequest`).
 */
class OptionController extends Controller
{
    use GuardsFrozenStructure;

    public function __construct(
        private readonly PsychometricAuthoringService $authoring,
    ) {}

    public function index(PsychometricQuestion $question): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $options = $question->options()->orderBy('sort_order')->get();

        return $this->success(
            message: 'Opciones de la pregunta.',
            data: AdminOptionResource::collection($options),
        );
    }

    public function store(OptionRequest $request, PsychometricQuestion $question): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $question->test)) {
            return $refusal;
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $option = $question->options()->create($data);

        return $this->success(
            message: 'Opción creada.',
            data: AdminOptionResource::make($option),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(OptionRequest $request, PsychometricQuestionOption $option): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $this->authoring->testOf($option))) {
            return $refusal;
        }

        $option->update($request->validated());

        return $this->success(
            message: 'Opción actualizada.',
            data: AdminOptionResource::make($option->fresh() ?? $option),
        );
    }

    public function destroy(PsychometricQuestionOption $option): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $this->authoring->testOf($option))) {
            return $refusal;
        }

        $option->delete();

        return $this->success(
            message: 'Opción eliminada.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }
}
