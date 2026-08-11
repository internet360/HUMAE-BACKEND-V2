<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricResult;
use App\Models\PsychometricTest;
use Illuminate\Support\Collection;

class PsychometricScoringService
{
    /**
     * Rendiciones calificadas que hacen falta antes de reportar un percentil.
     *
     * Un percentil contra 3 personas no es un percentil: es ruido con aire de
     * estadística, y acá lo va a leer alguien que decide una contratación. Por
     * debajo de este piso el campo queda `null` y la UI simplemente no lo muestra.
     */
    private const PERCENTILE_MIN_POPULATION = 20;

    /**
     * Calcula el resultado del intento y persiste el PsychometricResult.
     * Idempotente: si ya existe resultado, lo retorna sin recalcular.
     */
    public function score(PsychometricAttempt $attempt): PsychometricResult
    {
        $existing = $attempt->result;

        if ($existing !== null) {
            return $existing;
        }

        $test = $attempt->test;
        $questions = $test !== null
            ? $test->questions()->with('options')->get()
            : collect();

        $answers = $attempt->answers()->with('question.options', 'option')->get();

        $dimensionScores = $this->aggregateByDimension($answers, $questions);
        $totalScore = array_sum($dimensionScores);
        $passingScore = $test?->passing_score;
        $passed = $passingScore !== null && $totalScore >= $passingScore;

        return PsychometricResult::create([
            'psychometric_attempt_id' => $attempt->id,
            'total_score' => round($totalScore, 2),
            'percentile' => $test !== null
                ? $this->percentileAgainstPeers($test, $totalScore)
                : null,
            'grade' => $this->grade($totalScore, $this->maxPossibleScore($questions)),
            'passed' => $passed,
            'dimension_scores' => $dimensionScores,
            'summary' => $this->summary($dimensionScores),
            'recommendations' => null,
        ]);
    }

    /**
     * @param  Collection<int, PsychometricAnswer>  $answers
     * @param  Collection<int, PsychometricQuestion>  $questions
     * @return array<string, float>
     */
    private function aggregateByDimension(Collection $answers, Collection $questions): array
    {
        $byDimension = [];

        /** @var PsychometricAnswer $answer */
        foreach ($answers as $answer) {
            $question = $answer->question;
            if ($question === null) {
                continue;
            }

            $dimension = $question->dimension ?? 'general';

            $byDimension[$dimension] = ($byDimension[$dimension] ?? 0)
                + $this->awardedScore($answer, $question);
        }

        // Normaliza: redondea + asegura al menos 0
        foreach ($byDimension as $key => $value) {
            $byDimension[$key] = round(max(0.0, (float) $value), 2);
        }

        return $byDimension;
    }

    /**
     * Percentil del total contra quienes ya rindieron la MISMA prueba.
     *
     * Es una foto del momento de calificar, no un valor vivo: la población crece y
     * el percentil guardado no se recalcula. Se eligió así porque un valor que
     * cambia solo haría que dos reclutadores viendo el mismo expediente en
     * semanas distintas leyeran números distintos sin explicación. Si algún día se
     * necesita al día, se recalcula bajo demanda en lugar de leer la columna.
     *
     * Sólo cuenta intentos `completed` de la misma prueba: los vencidos no
     * midieron nada y los anulados fueron invalidados a mano, así que ninguno de
     * los dos es población de referencia.
     *
     * Devuelve `null` por debajo de `PERCENTILE_MIN_POPULATION`.
     */
    private function percentileAgainstPeers(PsychometricTest $test, float $totalScore): ?float
    {
        $peers = PsychometricResult::query()
            ->whereHas('attempt', function ($query) use ($test): void {
                $query->where('psychometric_test_id', $test->id)
                    ->where('status', AttemptStatus::Completed->value);
            });

        $population = (clone $peers)->count();

        if ($population < self::PERCENTILE_MIN_POPULATION) {
            return null;
        }

        $atOrBelow = (clone $peers)->where('total_score', '<=', $totalScore)->count();

        return round(($atOrBelow / $population) * 100, 2);
    }

    /**
     * Puntos que esta respuesta aportó al total: crudo → invertido → ponderado.
     *
     * Público porque la hoja de respuestas de HUMAE
     * (`PsychometricReportingService::answerSheet()`) muestra el aporte real de
     * cada ítem. Si duplicara la fórmula allá, el día que cambie el escalado la
     * auditoría mostraría números que no suman el total guardado.
     */
    public function awardedScore(PsychometricAnswer $answer, PsychometricQuestion $question): float
    {
        $raw = $this->rawScore($answer);

        $adjusted = $question->is_reverse_scored
            ? $this->reverseScore($raw, $question)
            : $raw;

        return $adjusted * (int) ($question->weight ?? 1);
    }

    /**
     * Puntaje crudo de una respuesta.
     *
     * Única fuente: el `score` de la opción elegida, leído de la base en el
     * momento de calificar. Nada de lo que el candidato escribe entra acá.
     *
     * Antes esta función tenía dos caminos más y ambos eran explotables:
     * preferir `$answer->score` (que el controller aceptaba del cliente) y caer
     * a `(float) $answer->value` cuando el valor era numérico. Cualquiera de los
     * dos permitía mandar 999999 y fabricarse el resultado. `answers.score`
     * sigue existiendo como caché que escribe el servidor, pero no se consulta
     * para calificar: si algún día una escritura lo corrompe, la nota no cambia.
     *
     * Los cinco tipos de `QuestionType` son de opción. El día que se agregue uno
     * abierto necesita su propia regla explícita, no un fallback silencioso.
     */
    private function rawScore(PsychometricAnswer $answer): float
    {
        $option = $answer->option;

        return $option !== null ? (float) $option->score : 0.0;
    }

    private function reverseScore(float $score, PsychometricQuestion $question): float
    {
        // Para Likert 1-5: reverse = 6 - score; 1-7: reverse = 8 - score
        $max = $this->likertMax($question);

        return (float) ($max + 1 - $score);
    }

    private function likertMax(PsychometricQuestion $question): int
    {
        return match ($question->type) {
            QuestionType::Likert7 => 7,
            QuestionType::Likert5 => 5,
            default => 5,
        };
    }

    /**
     * @param  Collection<int, PsychometricQuestion>  $questions
     */
    private function maxPossibleScore(Collection $questions): float
    {
        $total = 0.0;
        foreach ($questions as $q) {
            $maxPerQuestion = match ($q->type) {
                QuestionType::Likert7 => 7,
                QuestionType::Likert5 => 5,
                QuestionType::TrueFalse => 1,
                default => (int) ($q->options->max('score') ?? 1),
            };
            $total += $maxPerQuestion * (int) ($q->weight ?? 1);
        }

        return max(1.0, $total);
    }

    private function grade(float $total, float $max): string
    {
        $pct = $max > 0 ? ($total / $max) : 0.0;

        return match (true) {
            $pct >= 0.80 => 'A',
            $pct >= 0.60 => 'B',
            $pct >= 0.40 => 'C',
            default => 'D',
        };
    }

    /**
     * @param  array<string, float>  $scores
     */
    private function summary(array $scores): string
    {
        if ($scores === []) {
            return 'Sin dimensiones evaluadas.';
        }

        arsort($scores);
        $top = array_key_first($scores);

        return 'Dimensión más alta: '.$top.'.';
    }
}
