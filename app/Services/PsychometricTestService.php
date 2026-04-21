<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\CandidateProfile;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PsychometricTestService
{
    public function __construct(
        private readonly PsychometricScoringService $scoring,
    ) {}

    /**
     * Inicia un intento o retorna el existente in_progress del candidato.
     */
    public function startOrResume(
        CandidateProfile $profile,
        PsychometricTest $test,
        ?Request $request = null,
    ): PsychometricAttempt {
        $inProgress = PsychometricAttempt::where('candidate_profile_id', $profile->id)
            ->where('psychometric_test_id', $test->id)
            ->where('status', AttemptStatus::InProgress->value)
            ->first();

        if ($inProgress !== null) {
            return $inProgress;
        }

        return PsychometricAttempt::create([
            'candidate_profile_id' => $profile->id,
            'psychometric_test_id' => $test->id,
            'status' => AttemptStatus::InProgress->value,
            'started_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * Upsert de respuestas. Cada item del array de answers es:
     *   { question_id: int, option_id?: int|null, value?: string|null, time_spent_seconds?: int }
     *
     * @param  array<int, array<string, mixed>>  $answers
     */
    public function saveAnswers(PsychometricAttempt $attempt, array $answers): void
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new RuntimeException('El intento ya no está en progreso.');
        }

        $questionIds = collect($answers)->pluck('question_id')->filter()->unique();

        // Valida que todas las preguntas pertenezcan al mismo test
        $validQuestionIds = PsychometricQuestion::where('psychometric_test_id', $attempt->psychometric_test_id)
            ->whereIn('id', $questionIds)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($attempt, $answers, $validQuestionIds): void {
            foreach ($answers as $data) {
                $questionId = (int) ($data['question_id'] ?? 0);
                if (! in_array($questionId, $validQuestionIds, true)) {
                    continue;
                }

                PsychometricAnswer::updateOrCreate(
                    [
                        'psychometric_attempt_id' => $attempt->id,
                        'psychometric_question_id' => $questionId,
                    ],
                    [
                        'psychometric_question_option_id' => $data['option_id'] ?? null,
                        'value' => isset($data['value']) ? (string) $data['value'] : null,
                        'score' => isset($data['score']) ? (int) $data['score'] : null,
                        'time_spent_seconds' => isset($data['time_spent_seconds'])
                            ? (int) $data['time_spent_seconds']
                            : null,
                    ],
                );
            }
        });
    }

    public function submit(PsychometricAttempt $attempt): PsychometricAttempt
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            return $attempt; // Idempotente
        }

        $now = now();
        $durationSeconds = $attempt->started_at !== null
            ? (int) abs($attempt->started_at->diffInSeconds($now))
            : null;

        DB::transaction(function () use ($attempt, $now, $durationSeconds): void {
            $attempt->forceFill([
                'status' => AttemptStatus::Completed->value,
                'submitted_at' => $now,
                'duration_seconds' => $durationSeconds,
            ])->save();

            $this->scoring->score($attempt);
        });

        return $attempt->fresh(['result']) ?? $attempt;
    }
}
