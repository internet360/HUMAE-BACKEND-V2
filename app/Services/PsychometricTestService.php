<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\CandidateProfile;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricTest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PsychometricTestService
{
    public function __construct(
        private readonly PsychometricScoringService $scoring,
    ) {}

    /**
     * Inicia un intento, reanuda el que esté en curso, o se niega.
     *
     * Regla de producto: el candidato puede responder TODAS las pruebas activas
     * que el admin publique, pero **cada una una sola vez**. El límite es por
     * prueba (`max_attempts`, 1 por defecto, `null` ilimitado), no por persona:
     * completar Big Five no le cierra la puerta a un DISC que se publique después.
     *
     * Reanudar un intento en curso no pasa por el candado: bloquear a quien está
     * a mitad del cuestionario sería perderle las respuestas.
     *
     * @throws RuntimeException si no puede arrancar
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
            // Un intento cuyo tiempo ya venció no se reanuda: se cierra. Si no,
            // el límite de tiempo sería decorativo — bastaba con dejar la
            // pestaña abierta y volver al día siguiente.
            if (! $this->expireIfOverdue($inProgress, $test)) {
                return $inProgress;
            }
        }

        $this->assertAttemptsAvailable($profile, $test);

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
     * Upsert de respuestas. Cada item es:
     *   { question_id: int, option_id?: int|null, value?: string|null, time_spent_seconds?: int }
     *
     * El puntaje NO viene en la entrada: se deriva de la opción elegida. Ver
     * `SavePsychometricAnswersRequest`, que ya garantizó que cada pregunta
     * pertenece a la prueba del intento y cada opción a su pregunta.
     *
     * @param  array<int, array<string, mixed>>  $answers
     *
     * @throws RuntimeException si el intento no admite más respuestas
     */
    public function saveAnswers(PsychometricAttempt $attempt, array $answers): void
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new RuntimeException('El intento ya no está en progreso.');
        }

        if ($this->expireIfOverdue($attempt, $attempt->test)) {
            throw new RuntimeException('Se agotó el tiempo de la prueba.');
        }

        $this->assertAnswersMatchTest($attempt, $answers);

        $scoreByOption = $this->optionScores($answers);

        DB::transaction(function () use ($attempt, $answers, $scoreByOption): void {
            foreach ($answers as $data) {
                $questionId = (int) ($data['question_id'] ?? 0);
                $optionId = isset($data['option_id']) && $data['option_id'] !== ''
                    ? (int) $data['option_id']
                    : null;

                PsychometricAnswer::updateOrCreate(
                    [
                        'psychometric_attempt_id' => $attempt->id,
                        'psychometric_question_id' => $questionId,
                    ],
                    [
                        'psychometric_question_option_id' => $optionId,
                        'value' => isset($data['value']) ? (string) $data['value'] : null,
                        // Autoridad única del puntaje. Se persiste como caché
                        // para reportes; el scoring lo vuelve a leer de la
                        // opción de todos modos.
                        'score' => $optionId !== null ? ($scoreByOption[$optionId] ?? null) : null,
                        'time_spent_seconds' => isset($data['time_spent_seconds'])
                            ? (int) $data['time_spent_seconds']
                            : null,
                    ],
                );
            }
        });
    }

    /**
     * Cierra el intento y calcula el resultado.
     *
     * Si el tiempo venció, el intento queda `expired` y no se puntúa: enviar
     * fuera de plazo no puede valer lo mismo que enviar dentro.
     *
     * @throws RuntimeException si el intento venció por tiempo
     */
    public function submit(PsychometricAttempt $attempt): PsychometricAttempt
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            return $attempt; // Idempotente
        }

        if ($this->expireIfOverdue($attempt, $attempt->test)) {
            throw new RuntimeException('Se agotó el tiempo de la prueba.');
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

    /**
     * Anula un intento y le devuelve el cupo al candidato.
     *
     * Es la salida de soporte: el candidato contestó de mala fe, se le cortó la
     * conexión de un modo que no disparó el vencimiento, o HUMAE necesita
     * volverlo a medir con una versión nueva del instrumento. Sin esto, un límite
     * de un intento por prueba no tenía ninguna vía de reparación.
     *
     * No hace falta tocar ningún candado: `attemptsRemaining()` cuenta
     * `completed` y `expired`, nunca `cancelled`, así que anular libera el cupo
     * solo. Y `scoredAttempts()` filtra por `completed`, así que el resultado
     * anulado desaparece del expediente del reclutador y de la vista de la
     * empresa sin borrar nada: la fila queda para auditoría y sólo el admin la ve
     * en `allAttempts()`.
     *
     * @throws RuntimeException si ya estaba anulado
     */
    public function cancelAttempt(
        PsychometricAttempt $attempt,
        User $actor,
        ?string $reason = null,
    ): PsychometricAttempt {
        if ($attempt->status === AttemptStatus::Cancelled) {
            throw new RuntimeException('Este intento ya estaba anulado.');
        }

        $attempt->forceFill([
            'status' => AttemptStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
            'cancelled_by' => $actor->id,
        ])->save();

        return $attempt->fresh(['test', 'result']) ?? $attempt;
    }

    /**
     * Marca el intento como `expired` si pasó su límite de tiempo.
     *
     * @return bool true si lo expiró en esta llamada
     */
    private function expireIfOverdue(PsychometricAttempt $attempt, ?PsychometricTest $test): bool
    {
        $limit = $test?->time_limit_minutes;

        if ($limit === null || $limit <= 0 || $attempt->started_at === null) {
            return false;
        }

        if ($attempt->started_at->copy()->addMinutes($limit)->isFuture()) {
            return false;
        }

        $attempt->forceFill([
            'status' => AttemptStatus::Expired->value,
            'submitted_at' => $attempt->submitted_at ?? now(),
        ])->save();

        return true;
    }

    /**
     * ¿Puede este candidato arrancar (o continuar) esta prueba?
     *
     * Mismas reglas que `startOrResume()`, en forma de predicado, para que el
     * listado del candidato pueda decir la verdad ANTES de ofrecer un botón. Sin
     * esto la UI ofrecía "Volver a contestar" y el servidor respondía 409: la
     * pantalla prometía algo imposible.
     */
    public function canStart(CandidateProfile $profile, PsychometricTest $test): bool
    {
        // Continuar lo empezado siempre se puede.
        if ($this->inProgressAttempt($profile, $test) !== null) {
            return true;
        }

        return $this->attemptsRemaining($profile, $test);
    }

    private function inProgressAttempt(
        CandidateProfile $profile,
        PsychometricTest $test,
    ): ?PsychometricAttempt {
        return PsychometricAttempt::query()
            ->where('candidate_profile_id', $profile->id)
            ->where('psychometric_test_id', $test->id)
            ->where('status', AttemptStatus::InProgress->value)
            ->first();
    }

    /**
     * ¿Quedan intentos de ESTA prueba?
     *
     * Predicado separado del assert para que `canStart()` pueda consultarlo sin
     * atrapar excepciones, y para que la regla viva en un solo lugar.
     */
    private function attemptsRemaining(CandidateProfile $profile, PsychometricTest $test): bool
    {
        $max = $test->max_attempts;

        if ($max === null) {
            return true; // Ilimitado, declarado explícitamente en la prueba.
        }

        // Cuentan los intentos ya consumidos, no los cancelados por
        // administración: un intento que HUMAE anuló no debe gastarle el cupo al
        // candidato.
        $used = PsychometricAttempt::query()
            ->where('candidate_profile_id', $profile->id)
            ->where('psychometric_test_id', $test->id)
            ->whereIn('status', [
                AttemptStatus::Completed->value,
                AttemptStatus::Expired->value,
            ])
            ->count();

        return $used < $max;
    }

    /**
     * @throws RuntimeException
     */
    private function assertAttemptsAvailable(CandidateProfile $profile, PsychometricTest $test): void
    {
        if (! $this->attemptsRemaining($profile, $test)) {
            throw new RuntimeException(
                'Ya agotaste los intentos disponibles para esta prueba.',
            );
        }
    }

    /**
     * Invariante: cada pregunta pertenece a la prueba del intento y cada opción
     * a su pregunta.
     *
     * `SavePsychometricAnswersRequest` ya lo valida y responde 422 con el campo
     * exacto, así que por la vía HTTP esto nunca se dispara. Está igual porque el
     * puntaje se deriva del `option_id`: si mañana un comando, un seeder o un
     * endpoint nuevo llama a este servicio sin pasar por ese Form Request, la
     * regla de negocio no puede quedar colgada de quién lo invocó.
     *
     * @param  array<int, array<string, mixed>>  $answers
     *
     * @throws RuntimeException
     */
    private function assertAnswersMatchTest(PsychometricAttempt $attempt, array $answers): void
    {
        $optionsByQuestion = $attempt->test?->optionIdsByQuestion() ?? [];

        foreach ($answers as $data) {
            $questionId = (int) ($data['question_id'] ?? 0);

            if (! array_key_exists($questionId, $optionsByQuestion)) {
                throw new RuntimeException(
                    "La pregunta {$questionId} no pertenece a la prueba del intento.",
                );
            }

            $optionId = $data['option_id'] ?? null;

            if ($optionId === null || $optionId === '') {
                continue;
            }

            if (! in_array((int) $optionId, $optionsByQuestion[$questionId], true)) {
                throw new RuntimeException(
                    "La opción {$optionId} no pertenece a la pregunta {$questionId}.",
                );
            }
        }
    }

    /**
     * Puntajes reales de las opciones referidas en la entrada.
     *
     * Una sola consulta para todo el lote: la alternativa era leer la opción
     * dentro del loop y hacer N consultas por cuestionario.
     *
     * @param  array<int, array<string, mixed>>  $answers
     * @return array<int, int>
     */
    private function optionScores(array $answers): array
    {
        $optionIds = [];

        foreach ($answers as $data) {
            $optionId = $data['option_id'] ?? null;

            if ($optionId !== null && $optionId !== '') {
                $optionIds[] = (int) $optionId;
            }
        }

        if ($optionIds === []) {
            return [];
        }

        /** @var array<int, int> $scores */
        $scores = PsychometricQuestionOption::query()
            ->whereIn('id', array_unique($optionIds))
            ->pluck('score', 'id')
            ->map(fn ($score): int => (int) $score)
            ->all();

        return $scores;
    }
}
