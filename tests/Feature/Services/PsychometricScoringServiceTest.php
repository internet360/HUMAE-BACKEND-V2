<?php

declare(strict_types=1);

use App\Enums\QuestionType;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricTest;
use App\Services\PsychometricScoringService;

beforeEach(function (): void {
    $this->service = new PsychometricScoringService;
});

it('aggregates dimension scores from answers', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);

    $q1 = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'dimension' => 'extraversion',
        'weight' => 1,
        'is_reverse_scored' => false,
    ]);
    $q2 = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'dimension' => 'extraversion',
        'weight' => 1,
        'is_reverse_scored' => false,
    ]);
    $q3 = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'dimension' => 'neuroticism',
        'weight' => 1,
        'is_reverse_scored' => false,
    ]);

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $q1->id,
        'score' => 4,
    ]);
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $q2->id,
        'score' => 5,
    ]);
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $q3->id,
        'score' => 2,
    ]);

    $result = $this->service->score($attempt);

    expect((float) $result->dimension_scores['extraversion'])->toBe(9.0);
    expect((float) $result->dimension_scores['neuroticism'])->toBe(2.0);
    expect((float) $result->total_score)->toBe(11.0);
    expect($result->passed)->toBeFalse();
});

it('applies reverse scoring on Likert5 questions', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'dimension' => 'extraversion',
        'weight' => 1,
        'is_reverse_scored' => true,
    ]);

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $question->id,
        'score' => 2, // reverse: 6 - 2 = 4
    ]);

    $result = $this->service->score($attempt);

    expect((float) $result->total_score)->toBe(4.0);
});

it('is idempotent — returns existing result without recomputing', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'weight' => 1,
    ]);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $question->id,
        'score' => 3,
    ]);

    $first = $this->service->score($attempt);
    $second = $this->service->score($attempt->fresh());

    expect($second->id)->toBe($first->id);
});

it('marks result as passed when total_score exceeds passing_score', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => 10]);
    $q = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'weight' => 1,
    ]);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $q->id,
        'score' => 15,
    ]);

    $result = $this->service->score($attempt);

    expect($result->passed)->toBeTrue();
});

it('returns summary "Sin dimensiones evaluadas" when there are no answers', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    $result = $this->service->score($attempt);

    expect($result->summary)->toBe('Sin dimensiones evaluadas.');
    expect((float) $result->total_score)->toBe(0.0);
});
