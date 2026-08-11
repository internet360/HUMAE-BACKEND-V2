<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Psychometrics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Psychometrics\DuplicateTestRequest;
use App\Http\Requests\Admin\Psychometrics\TestRequest;
use App\Http\Resources\V1\Admin\Psychometrics\AdminTestResource;
use App\Models\PsychometricTest;
use App\Services\PsychometricAuthoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * CRUD de pruebas psicométricas. Protegido por el permiso Spatie
 * `psychometric.manage` (rol admin).
 *
 * A diferencia del listado del candidato (`/me/psychometrics/tests`), este
 * incluye las pruebas inactivas y expone el estado de congelamiento — ver
 * `PsychometricAuthoringService` para el porqué de esa regla.
 */
class TestController extends Controller
{
    public function __construct(
        private readonly PsychometricAuthoringService $authoring,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $term = $request->string('q')->trim()->toString();

        $tests = PsychometricTest::query()
            ->when($term !== '', function ($query) use ($term): void {
                $pattern = '%'.$term.'%';
                $query->where(function ($inner) use ($pattern): void {
                    $inner->where('name', 'like', $pattern)
                        ->orWhere('code', 'like', $pattern);
                });
            })
            ->withCount(['questions', 'attempts'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // `attempts_count` ya vino en la misma consulta: derivar `is_in_use` de
        // ahí evita un `exists()` por fila.
        $tests->each(function (PsychometricTest $test): void {
            $test->setAttribute('is_in_use', ($test->attempts_count ?? 0) > 0);
        });

        return $this->success(
            message: 'Pruebas psicométricas.',
            data: AdminTestResource::collection($tests),
        );
    }

    public function show(PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $test->loadCount(['questions', 'attempts']);
        $test->load([
            'sections' => fn ($q) => $q->orderBy('sort_order'),
            'questions' => fn ($q) => $q->orderBy('sort_order'),
            'questions.options' => fn ($q) => $q->orderBy('sort_order'),
        ]);
        $test->setAttribute('is_in_use', $this->authoring->isInUse($test));

        return $this->success(
            message: 'Prueba psicométrica.',
            data: AdminTestResource::make($test),
        );
    }

    public function store(TestRequest $request): JsonResponse
    {
        $this->authorize('psychometric.manage');

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $test = PsychometricTest::create($data);
        $test->setAttribute('is_in_use', false);

        return $this->success(
            message: 'Prueba creada.',
            data: AdminTestResource::make($test),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    /**
     * Los campos congelados no se aplican, y se dicen.
     *
     * Rechazar toda la petición con 409 obligaría al admin a partir en dos un
     * cambio de nombre + puntaje de corte; ignorarlos en silencio lo dejaría
     * creyendo que se guardó. Se aplica lo aplicable y se informa lo demás.
     */
    public function update(TestRequest $request, PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $filtered = $this->authoring->filterLockedFields($test, $validated);

        if ($filtered['data'] !== []) {
            $test->update($filtered['data']);
        }

        $fresh = $test->fresh() ?? $test;
        $fresh->setAttribute('is_in_use', $this->authoring->isInUse($fresh));

        $message = $filtered['rejected'] === []
            ? 'Prueba actualizada.'
            : 'Prueba actualizada. Sin aplicar (la prueba ya tiene intentos): '
                .implode(', ', $filtered['rejected']).'. Duplicá la prueba para cambiarlos.';

        return $this->success(
            message: $message,
            data: AdminTestResource::make($fresh),
        );
    }

    public function destroy(PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        try {
            $this->authoring->deleteTest($test);
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        return $this->success(
            message: 'Prueba eliminada.',
            status: HttpStatus::HTTP_NO_CONTENT,
        );
    }

    /**
     * Versionado: copia profunda, inactiva y editable.
     */
    public function duplicate(DuplicateTestRequest $request, PsychometricTest $test): JsonResponse
    {
        $this->authorize('psychometric.manage');

        $test->load(['sections', 'questions.options']);

        try {
            $copy = $this->authoring->duplicate(
                $test,
                (string) $request->validated('code'),
                $request->validated('name'),
            );
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $copy->loadCount(['questions', 'attempts']);
        $copy->setAttribute('is_in_use', false);

        return $this->success(
            message: 'Prueba duplicada. La copia nace inactiva y editable.',
            data: AdminTestResource::make($copy),
            status: HttpStatus::HTTP_CREATED,
        );
    }
}
