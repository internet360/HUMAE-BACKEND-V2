<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricResult;
use Illuminate\Support\Collection;

class PsychometricScoringService
{
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
            'percentile' => null,
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
            $rawScore = $this->rawScore($answer, $question);

            $adjusted = $question->is_reverse_scored
                ? $this->reverseScore($rawScore, $question)
                : $rawScore;

            $weighted = $adjusted * (int) ($question->weight ?? 1);

            $byDimension[$dimension] = ($byDimension[$dimension] ?? 0) + $weighted;
        }

        // Normaliza: redondea + asegura al menos 0
        foreach ($byDimension as $key => $value) {
            $byDimension[$key] = round(max(0.0, (float) $value), 2);
        }

        return $byDimension;
    }

    private function rawScore(PsychometricAnswer $answer, PsychometricQuestion $question): float
    {
        if ($answer->score !== null) {
            return (float) $answer->score;
        }

        $option = $answer->option;
        if ($option !== null) {
            return (float) $option->score;
        }

        // Respuestas numéricas crudas (p.ej. Likert con value numérico)
        if ($answer->value !== null && is_numeric($answer->value)) {
            return (float) $answer->value;
        }

        return 0.0;
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
