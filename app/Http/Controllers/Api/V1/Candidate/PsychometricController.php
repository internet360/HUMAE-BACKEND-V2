<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Candidate;

use App\Enums\AttemptStatus;
use App\Http\Controllers\Api\V1\Candidate\Concerns\ResolvesCandidateProfile;
use App\Http\Controllers\Controller;
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

        $tests->each(function (PsychometricTest $test) use ($latestAttempts): void {
            $test->setAttribute('latest_attempt', $latestAttempts->get($test->id));
        });

        return $this->success(
            message: 'Tests disponibles.',
            data: TestResource::collection($tests),
        );
    }

    public function startAttempt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'test_id' => ['required', 'integer', 'exists:psychometric_tests,id'],
        ]);

        $test = PsychometricTest::where('id', $validated['test_id'])
            ->where('is_active', true)
            ->first();

        if ($test === null) {
            return $this->error('Test no disponible.', status: HttpStatus::HTTP_NOT_FOUND);
        }

        $attempt = $this->service->startOrResume(
            $this->profile($request),
            $test,
            $request,
        );

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

    public function saveAnswers(Request $request, PsychometricAttempt $attempt): JsonResponse
    {
        $this->ensureOwned($request, $attempt->candidate_profile_id);

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['nullable', 'integer'],
            'answers.*.value' => ['nullable', 'string', 'max:400'],
            'answers.*.score' => ['nullable', 'integer'],
            'answers.*.time_spent_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $this->service->saveAnswers($attempt, $validated['answers']);
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

        $submitted = $this->service->submit($attempt);
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
