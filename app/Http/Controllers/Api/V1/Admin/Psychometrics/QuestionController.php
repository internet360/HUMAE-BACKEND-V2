<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics;

use App\Http\Controllers\Api\V1\Admin\Psychometrics\Concerns\GuardsFrozenStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Psychometrics\QuestionRequest;
use App\Http\Resources\V1\Admin\Psychometrics\AdminQuestionResource;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricTest;
use App\Services\PsychometricAuthoringService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class QuestionController extends Controller
{
    use GuardsFrozenStructure;

    public function __construct(
        private readonly PsychometricAuthoringService $authoring,
    ) {}

    public function index(PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $questions = $test->questions()
            ->with(['options' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return $this->success(
            message: 'Preguntas de la prueba.',
            data: AdminQuestionResource::collection($questions),
        );
    }

    public function store(QuestionRequest $request, PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $test)) {
            return $refusal;
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $question = $test->questions()->create($this->toColumns($data));

        return $this->success(
            message: 'Pregunta creada.',
            data: AdminQuestionResource::make($question->load('options')),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function update(QuestionRequest $request, PsychometricQuestion $question): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $question->test)) {
            return $refusal;
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $question->update($this->toColumns($data));

        return $this->success(
            message: 'Pregunta actualizada.',
            data: AdminQuestionResource::make(($question->fresh(['options']) ?? $question)),
        );
    }

    public function destroy(PsychometricQuestion $question): JsonResponse
    {
        $this->authorize('psychometric.manage');

        if ($refusal = $this->refuseIfFrozen($this->authoring, $question->test)) {
            return $refusal;
        }

        // Las opciones caen por cascadeOnDelete. Las respuestas usan
        // restrictOnDelete, pero no puede haber ninguna: si hubiera intentos, el
        // guardia de arriba ya habría cortado.
        $question->delete();

        return $this->success(
            message: 'Pregunta eliminada.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }

    /**
     * Traduce el payload de la API a columnas.
     *
     * `section_id` se expone así en la API —consistente con el recurso— pero la
     * columna es `psychometric_test_section_id`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        if (array_key_exists('section_id', $data)) {
            $data['psychometric_test_section_id'] = $data['section_id'];
            unset($data['section_id']);
        }

        return $data;
    }
}
