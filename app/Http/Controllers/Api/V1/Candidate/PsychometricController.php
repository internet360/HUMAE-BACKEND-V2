<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidate\SavePsychometricAnswersRequest;
use App\Http\Requests\Candidate\StartPsychometricAttemptRequest;
use App\Http\Resources\V1\Psychometric\AttemptResource;
use App\Http\Resources\V1\Psychometric\ResultResource;
use App\Http\Resources\V1\Psychometric\TestResource;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use App\Services\PsychometricTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

class PsychometricController extends Controller
{
    use ResolvesCandidateProfile;

    public function __construct(
        private readonly PsychometricTestService $service,
    ) {}

    public function listTests(Request $request): JsonResponse
    {
        $tests = PsychometricTest::query()
            ->where('is_active', true)
            ->with(['questions' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $profile = $this->profile($request);

        $latestAttempts = PsychometricAttempt::query()
            ->where('candidate_profile_id', $profile->id)
            ->whereIn('psychometric_test_id', $tests->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->keyBy('psychometric_test_id');

        // `can_start` compone las mismas reglas que `startOrResume()` — el
        // candado global de "una prueba por candidato" y el `max_attempts` de
        // cada prueba. Se envía para que la pantalla no ofrezca un botón que el
        // servidor va a rechazar con 409, que es exactamente lo que pasaba con
        // el viejo "Volver a contestar".
        $tests->each(function (PsychometricTest $test) use ($latestAttempts, $profile): void {
            $test->setAttribute('latest_attempt', $latestAttempts->get($test->id));
            $test->setAttribute('can_start', $this->service->canStart($profile, $test));
        });

        return $this->success(
            message: 'Tests disponibles.',
            data: TestResource::collection($tests),
        );
    }

    public function startAttempt(StartPsychometricAttemptRequest $request): JsonResponse
    {
        $test = PsychometricTest::where('id', $request->validated('test_id'))
            ->where('is_active', true)
            ->first();

        if ($test === null) {
            return $this->error('Test no disponible.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        try {
            $attempt = $this->service->startOrResume(
                $this->profile($request),
                $test,
                $request,
            );
        } catch (Throwable $e) {
            // Intentos agotados: el recurso existe y la petición es válida, pero
            // el estado del candidato la prohíbe.
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $attempt->load([
            'test.questions.options' => fn ($q) => $q->orderBy('sort_order'),
            'answers',
        ]);

        return $this->success(
            message: 'Intento iniciado.',
            data: AttemptResource::make($attempt),
            status: HttpStatus::HTTP_CREATED,
        );
    }

    public function showAttempt(Request $request, PsychometricAttempt $attempt): JsonResponse
    {
        $this->ensureOwned($request, $attempt->candidate_profile_id);

        $attempt->load([
            'test.questions.options' => fn ($q) => $q->orderBy('sort_order'),
            'answers',
            'result',
        ]);

        return $this->success(
            message: 'Intento actual.',
            data: AttemptResource::make($attempt),
        );
    }

    /**
     * La pertenencia del intento la verifica `SavePsychometricAnswersRequest::authorize()`,
     * que corre antes de las reglas — por eso acá no hay `ensureOwned()`.
     */
    public function saveAnswers(SavePsychometricAnswersRequest $request, PsychometricAttempt $attempt): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $answers */
        $answers = $request->validated('answers');

        try {
            $this->service->saveAnswers($attempt, $answers);
        } catch (Throwable $e) {
            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $attempt->load(['answers']);

        return $this->success(
            message: 'Respuestas guardadas.',
            data: AttemptResource::make($attempt),
        );
    }

    public function submitAttempt(Request $request, PsychometricAttempt $attempt): JsonResponse
    {
        $this->ensureOwned($request, $attempt->candidate_profile_id);

        if ($attempt->status === AttemptStatus::Completed) {
            $attempt->load(['result']);

            return $this->success(
                message: 'El intento ya había sido enviado.',
                data: AttemptResource::make($attempt),
            );
        }

        try {
            $submitted = $this->service->submit($attempt);
        } catch (Throwable $e) {
            // Tiempo agotado: el intento quedó `expired` y sin resultado.
            $attempt->refresh();

            return $this->error(
                message: $e->getMessage(),
                status: HttpStatus::HTTP_CONFLICT,
            );
        }

        $submitted->load(['result']);

        return $this->success(
            message: 'Intento enviado.',
            data: AttemptResource::make($submitted),
        );
    }

    public function showResult(Request $request, PsychometricAttempt $attempt): JsonResponse
    {
        $this->ensureOwned($request, $attempt->candidate_profile_id);

        $result = $attempt->result;

        if ($result === null) {
            return $this->error(
                message: 'Aún no hay resultado para este intento.',
                status: HttpStatus::HTTP_NOT_FOUND,
            );
        }

        return $this->success(
            message: 'Resultado.',
            data: ResultResource::make($result),
        );
    }
}
