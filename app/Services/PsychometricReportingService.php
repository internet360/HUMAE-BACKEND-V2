<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\CandidateProfile;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

/**
 * Lectura de resultados psicométricos por parte de terceros (reclutador,
 * empresa cliente, admin).
 *
 * Existe separado de `PsychometricTestService` —que es el autoservicio del
 * candidato— porque las preguntas son distintas: acá nunca se escribe nada, y lo
 * que cambia entre consumidores es CUÁNTO se muestra, no CÓMO se calcula.
 */
class PsychometricReportingService
{
    public function __construct(
        private readonly PsychometricScoringService $scoring,
    ) {}

    /**
     * Hoja de respuestas de un intento, ítem por ítem, para HUMAE.
     *
     * Se recorre desde las PREGUNTAS y no desde las respuestas: así los ítems
     * que el candidato dejó en blanco aparecen igual, que es justo lo que se
     * quiere ver al auditar un resultado bajo. Recorriendo respuestas
     * desaparecerían sin dejar rastro.
     *
     * `awarded_points` sale de `PsychometricScoringService::awardedScore()`, el
     * mismo método que sumó el total — no una reimplementación.
     *
     * @return list<array<string, mixed>>
     */
    public function answerSheet(PsychometricAttempt $attempt): array
    {
        $test = $attempt->test;

        if ($test === null) {
            return [];
        }

        $questions = $test->questions()
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $answers = $attempt->answers()->with('option')->get()
            ->keyBy('psychometric_question_id');

        // `array_values` por lo mismo que en `PsychometricTest::optionIdsByQuestion()`:
        // `map()->all()` conserva las llaves de la colección y PHPStan nivel 8 lo
        // tipa como `array`, no como `list`.
        return array_values($questions->map(function (PsychometricQuestion $question) use ($answers): array {
            $answer = $answers->get($question->id);
            $option = $answer?->option;

            return [
                'question_id' => $question->id,
                'prompt' => $question->prompt,
                'type' => $question->type?->value,
                'dimension' => $question->dimension,
                'is_reverse_scored' => $question->is_reverse_scored,
                'weight' => $question->weight,
                'answered' => $answer !== null,
                'chosen' => $option !== null ? [
                    'option_id' => $option->id,
                    'label' => $option->label,
                    'value' => $option->value,
                    'points' => $option->score,
                ] : null,
                'awarded_points' => $answer !== null
                    ? $this->scoring->awardedScore($answer, $question)
                    : null,
            ];
        })->all());
    }

    /**
     * Registra la interpretación de HUMAE sobre un resultado.
     *
     * Es la única escritura de este servicio, y vive acá porque no altera la
     * medición: `total_score` y `dimension_scores` no se tocan. Lo que se guarda es
     * la lectura humana de esos números, firmada con quién la hizo y cuándo — que
     * es lo que vuelve utilizables las columnas `recommendations`, `reviewed_by` y
     * `reviewed_at`, hasta ahora muertas.
     *
     * @throws RuntimeException si el intento no tiene resultado que interpretar
     */
    public function annotate(
        PsychometricAttempt $attempt,
        User $reviewer,
        ?string $recommendations,
    ): PsychometricResult {
        $result = $attempt->result;

        if ($result === null) {
            throw new RuntimeException(
                'Este intento no tiene resultado: no hay nada que interpretar.',
            );
        }

        $result->forceFill([
            'recommendations' => $recommendations,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        return $result->fresh() ?? $result;
    }

    /**
     * Intentos calificados de un candidato: sólo `completed` y con resultado.
     *
     * Es lo que ve el reclutador y la empresa. Un intento en curso no dice nada
     * y uno vencido no se calificó, así que mostrarlos sería ruido que invita a
     * conclusiones falsas.
     *
     * @return EloquentCollection<int, PsychometricAttempt>
     */
    public function scoredAttempts(CandidateProfile $profile): EloquentCollection
    {
        return PsychometricAttempt::query()
            ->where('candidate_profile_id', $profile->id)
            ->where('status', AttemptStatus::Completed->value)
            ->whereHas('result')
            ->with(['test', 'result'])
            ->orderByDesc('submitted_at')
            ->get();
    }

    /**
     * TODOS los intentos, en cualquier estado.
     *
     * Para el admin: "realizado/respondido" incluye el que quedó a medias y el
     * que se venció por tiempo — justo los casos que se necesitan para atender
     * un reclamo de "rendí y no aparece".
     *
     * @return EloquentCollection<int, PsychometricAttempt>
     */
    public function allAttempts(CandidateProfile $profile): EloquentCollection
    {
        return PsychometricAttempt::query()
            ->where('candidate_profile_id', $profile->id)
            ->with(['test', 'result'])
            ->orderByDesc('created_at')
            ->get();
    }
}
