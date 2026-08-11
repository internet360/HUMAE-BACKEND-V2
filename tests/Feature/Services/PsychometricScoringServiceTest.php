<?php

declare(strict_types=1);

use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricResult;
use App\Models\PsychometricTest;
use App\Services\PsychometricScoringService;

beforeEach(function (): void {
    $this->service = new PsychometricScoringService;
});

/**
 * Pregunta Likert 1-5 con sus cinco opciones puntuadas.
 *
 * Las opciones no son decorado del test: desde el blindaje, el `score` de la
 * opción es la ÚNICA fuente de puntaje. La versión anterior de estas pruebas
 * escribía `score` directo en la respuesta sin opción alguna — es decir,
 * ejercitaba el camino explotable y lo daba por correcto.
 *
 * @param  array<string, mixed>  $attrs
 */
function likertQuestion(PsychometricTest $test, array $attrs = []): PsychometricQuestion
{
    $question = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'type' => QuestionType::Likert5,
        'weight' => 1,
        'is_reverse_scored' => false,
        ...$attrs,
    ]);

    foreach (range(1, 5) as $score) {
        PsychometricQuestionOption::factory()->create([
            'psychometric_question_id' => $question->id,
            'label' => "Opción {$score}",
            'value' => (string) $score,
            'score' => $score,
        ]);
    }

    return $question;
}

/**
 * Responde eligiendo la opción que vale `$score`.
 */
function answerWith(PsychometricAttempt $attempt, PsychometricQuestion $question, int $score): void
{
    $option = $question->options()->where('score', $score)->firstOrFail();

    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $question->id,
        'psychometric_question_option_id' => $option->id,
        // Nulo a propósito: el scoring no lee esta columna.
        'score' => null,
    ]);
}

it('aggregates dimension scores from answers', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);

    $q1 = likertQuestion($test, ['dimension' => 'extraversion']);
    $q2 = likertQuestion($test, ['dimension' => 'extraversion']);
    $q3 = likertQuestion($test, ['dimension' => 'neuroticism']);

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    answerWith($attempt, $q1, 4);
    answerWith($attempt, $q2, 5);
    answerWith($attempt, $q3, 2);

    $result = $this->service->score($attempt);

    expect((float) $result->dimension_scores['extraversion'])->toBe(9.0);
    expect((float) $result->dimension_scores['neuroticism'])->toBe(2.0);
    expect((float) $result->total_score)->toBe(11.0);
    expect($result->passed)->toBeFalse();
});

it('applies reverse scoring on Likert5 questions', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test, [
        'dimension' => 'extraversion',
        'is_reverse_scored' => true,
    ]);

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    answerWith($attempt, $question, 2); // invertido: 6 - 2 = 4

    $result = $this->service->score($attempt);

    expect((float) $result->total_score)->toBe(4.0);
});

it('ignores the score column persisted on the answer', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test, ['dimension' => 'extraversion']);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    $option = $question->options()->where('score', 3)->firstOrFail();

    // Caché envenenada: si el scoring volviera a confiar en esta columna, el
    // total daría 999999 en lugar del 3 que vale la opción elegida.
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $question->id,
        'psychometric_question_option_id' => $option->id,
        'score' => 999999,
    ]);

    $result = $this->service->score($attempt);

    expect((float) $result->total_score)->toBe(3.0);
});

it('ignores a numeric value sent instead of an option', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test, ['dimension' => 'extraversion']);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    // El otro camino que existía: `value` numérico se casteaba a puntaje.
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $question->id,
        'psychometric_question_option_id' => null,
        'value' => '999999',
        'score' => null,
    ]);

    $result = $this->service->score($attempt);

    expect((float) $result->total_score)->toBe(0.0);
});

it('is idempotent — returns existing result without recomputing', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    answerWith($attempt, $question, 3);

    $first = $this->service->score($attempt);
    $second = $this->service->score($attempt->fresh());

    expect($second->id)->toBe($first->id);
});

it('marks result as passed when total_score exceeds passing_score', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => 10]);

    $q1 = likertQuestion($test, ['dimension' => 'apertura']);
    $q2 = likertQuestion($test, ['dimension' => 'apertura']);
    $q3 = likertQuestion($test, ['dimension' => 'apertura']);

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    answerWith($attempt, $q1, 4);
    answerWith($attempt, $q2, 4);
    answerWith($attempt, $q3, 5); // 13 >= 10

    $result = $this->service->score($attempt);

    expect((float) $result->total_score)->toBe(13.0);
    expect($result->passed)->toBeTrue();
});

it('withholds the percentile until there is a real population to compare against', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test, ['dimension' => 'apertura']);

    // Cinco rendiciones previas: muy pocas para que un percentil signifique algo.
    for ($i = 0; $i < 5; $i++) {
        $peer = PsychometricAttempt::factory()->create([
            'psychometric_test_id' => $test->id,
            'status' => AttemptStatus::Completed,
        ]);
        PsychometricResult::factory()->create([
            'psychometric_attempt_id' => $peer->id,
            'total_score' => 10,
        ]);
    }

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    answerWith($attempt, $question, 5);

    // Un percentil contra 5 personas es ruido con aire de estadística, y lo lee
    // alguien que decide una contratación.
    expect($this->service->score($attempt)->percentile)->toBeNull();
});

it('reports the percentile once the population is large enough', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test, ['dimension' => 'apertura']);

    // 20 rendiciones previas con puntaje 1: el piso exacto.
    for ($i = 0; $i < 20; $i++) {
        $peer = PsychometricAttempt::factory()->create([
            'psychometric_test_id' => $test->id,
            'status' => AttemptStatus::Completed,
        ]);
        PsychometricResult::factory()->create([
            'psychometric_attempt_id' => $peer->id,
            'total_score' => 1,
        ]);
    }

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    answerWith($attempt, $question, 5); // total 5, por encima de las 20

    expect((float) $this->service->score($attempt)->percentile)->toBe(100.0);
});

it('ignores expired and cancelled attempts as reference population', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $question = likertQuestion($test, ['dimension' => 'apertura']);

    // 25 resultados, pero de intentos que no midieron o fueron invalidados a
    // mano: no son población de referencia, así que no alcanzan el piso.
    foreach ([AttemptStatus::Expired, AttemptStatus::Cancelled] as $status) {
        for ($i = 0; $i < 13; $i++) {
            $peer = PsychometricAttempt::factory()->create([
                'psychometric_test_id' => $test->id,
                'status' => $status,
            ]);
            PsychometricResult::factory()->create([
                'psychometric_attempt_id' => $peer->id,
                'total_score' => 1,
            ]);
        }
    }

    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
    answerWith($attempt, $question, 4);

    expect($this->service->score($attempt)->percentile)->toBeNull();
});

it('returns summary "Sin dimensiones evaluadas" when there are no answers', function (): void {
    $test = PsychometricTest::factory()->create(['passing_score' => null]);
    $attempt = PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);

    $result = $this->service->score($attempt);

    expect($result->summary)->toBe('Sin dimensiones evaluadas.');
    expect((float) $result->total_score)->toBe(0.0);
});
