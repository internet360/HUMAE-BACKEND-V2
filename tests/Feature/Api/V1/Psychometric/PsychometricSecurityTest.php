<?php

declare(strict_types=1);

use App\Enums\AttemptStatus;
use App\Enums\UserRole;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricTest;
use App\Models\User;
use Database\Seeders\PsychometricBigFiveSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PsychometricBigFiveSeeder::class);

    $this->test = PsychometricTest::where('code', 'big-five-25')->firstOrFail();
});

/**
 * Nombre propio a propósito: `actAsCandidate()` ya la define
 * `PsychometricFlowTest.php` y las funciones de Pest son globales al proceso.
 */
function actAsFreshCandidate(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Arranca (o reanuda) un intento y devuelve su id.
 *
 * `assertCreated()` no es decoración: sin él, una petición rechazada devolvía
 * `data: null`, el `(int)` lo convertía en `0`, y una aserción como
 * `expect($second)->not->toBe($first)` pasaba con el intento que nunca se creó.
 * Un helper que se come los errores produce tests verdes sobre comportamiento
 * inexistente — pasó de verdad al agregar el candado global.
 */
function startAttempt(PsychometricTest $test): int
{
    return (int) test()->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])
        ->assertCreated()
        ->json('data.id');
}

/** Opción de una pregunta de la prueba, por su puntaje. */
function optionScoring(PsychometricQuestion $question, int $score): PsychometricQuestionOption
{
    return PsychometricQuestionOption::where('psychometric_question_id', $question->id)
        ->where('score', $score)
        ->firstOrFail();
}

it('refuses a client-supplied score outright', function (): void {
    actAsFreshCandidate();
    $attemptId = startAttempt($this->test);

    $question = PsychometricQuestion::where('psychometric_test_id', $this->test->id)->firstOrFail();

    $response = $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => [[
            'question_id' => $question->id,
            'option_id' => optionScoring($question, 1)->id,
            'score' => 999999,
        ]],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('answers.0.score');

    expect(PsychometricAnswer::where('psychometric_attempt_id', $attemptId)->count())->toBe(0);
});

it('derives the stored score from the chosen option, never from the client', function (): void {
    actAsFreshCandidate();
    $attemptId = startAttempt($this->test);

    $question = PsychometricQuestion::where('psychometric_test_id', $this->test->id)->firstOrFail();
    $option = optionScoring($question, 2);

    $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => [[
            'question_id' => $question->id,
            'option_id' => $option->id,
            // Un `value` numérico alto era el otro camino para inflar el puntaje.
            'value' => '999999',
        ]],
    ])->assertOk();

    $answer = PsychometricAnswer::where('psychometric_attempt_id', $attemptId)->firstOrFail();

    expect($answer->score)->toBe(2);
});

it('rejects an option that belongs to a different question', function (): void {
    actAsFreshCandidate();
    $attemptId = startAttempt($this->test);

    $questions = PsychometricQuestion::where('psychometric_test_id', $this->test->id)
        ->orderBy('id')
        ->take(2)
        ->get();

    $target = $questions[0];
    $foreignOption = optionScoring($questions[1], 5);

    $response = $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => [[
            'question_id' => $target->id,
            'option_id' => $foreignOption->id,
        ]],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('answers.0.option_id');

    expect(PsychometricAnswer::where('psychometric_attempt_id', $attemptId)->count())->toBe(0);
});

it('rejects a question that belongs to a different test instead of skipping it silently', function (): void {
    actAsFreshCandidate();
    $attemptId = startAttempt($this->test);

    $otherTest = PsychometricTest::factory()->create(['is_active' => true]);
    $foreignQuestion = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $otherTest->id,
    ]);

    $response = $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => [[
            'question_id' => $foreignQuestion->id,
        ]],
    ]);

    // Antes devolvía 200 y descartaba la respuesta con un `continue`.
    $response->assertStatus(422)->assertJsonValidationErrors('answers.0.question_id');
});

it('returns 404 for another candidate attempt before validating the payload', function (): void {
    actAsFreshCandidate();
    $attemptId = startAttempt($this->test);

    // Segundo candidato apuntando al intento del primero.
    actAsFreshCandidate();

    $response = $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => [['question_id' => 999999]],
    ]);

    // Si la validación corriera antes que la autorización, esto sería 422 — y
    // ese 422 revelaría a qué prueba pertenece el intento de otra persona.
    $response->assertStatus(404);
});

it('enforces max_attempts per test', function (): void {
    actAsFreshCandidate();

    expect($this->test->max_attempts)->toBe(1);

    $attemptId = startAttempt($this->test);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$attemptId}/submit")->assertOk();

    $response = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $this->test->id]);

    $response->assertStatus(409);
    expect(PsychometricAttempt::where('psychometric_test_id', $this->test->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Alcance del límite: por PRUEBA, no por candidato
|--------------------------------------------------------------------------
| Regla de producto: el candidato responde todas las pruebas activas que el admin
| publique, pero cada una una sola vez. Completar Big Five no le cierra un DISC
| que se publique después.
*/

it('lets a candidate answer a second, different test after finishing the first', function (): void {
    actAsFreshCandidate();

    $other = PsychometricTest::factory()->create([
        'code' => 'disc-basico',
        'is_active' => true,
    ]);

    $first = startAttempt($this->test);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$first}/submit")->assertOk();

    // Prueba distinta: su propio cupo, intacto.
    $second = startAttempt($other);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$second}/submit")->assertOk();

    expect(PsychometricAttempt::where('candidate_profile_id', PsychometricAttempt::find($first)->candidate_profile_id)
        ->where('status', AttemptStatus::Completed)
        ->count())->toBe(2);
});

it('still refuses a second attempt of the SAME test', function (): void {
    actAsFreshCandidate();

    $first = startAttempt($this->test);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$first}/submit")->assertOk();

    $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $this->test->id])
        ->assertStatus(409);
});

it('resumes an in-progress attempt of another test instead of refusing it', function (): void {
    actAsFreshCandidate();

    $other = PsychometricTest::factory()->create(['code' => 'disc-basico', 'is_active' => true]);

    $pending = startAttempt($other);
    $first = startAttempt($this->test);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$first}/submit")->assertOk();

    $resumed = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $other->id])
        ->assertCreated()
        ->json('data.id');

    expect($resumed)->toBe($pending);
});

it('tells the listing which tests can be started, per test', function (): void {
    actAsFreshCandidate();
    PsychometricTest::factory()->create(['code' => 'disc-basico', 'is_active' => true]);

    $before = $this->getJson('/api/v1/me/psychometrics/tests')->assertOk();
    expect(collect($before->json('data'))->pluck('can_start')->all())->each->toBeTrue();

    $first = startAttempt($this->test);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$first}/submit")->assertOk();

    $after = collect($this->getJson('/api/v1/me/psychometrics/tests')->assertOk()->json('data'))
        ->keyBy('code');

    // Sólo la respondida deja de ser arrancable. La otra sigue disponible: es la
    // diferencia entre un límite por prueba y uno por persona.
    expect($after['big-five-25']['can_start'])->toBeFalse();
    expect($after['disc-basico']['can_start'])->toBeTrue();
});

it('lets max_attempts null reopen a test that expired', function (): void {
    actAsFreshCandidate();
    $this->test->update(['max_attempts' => null, 'time_limit_minutes' => 30]);

    $abandoned = startAttempt($this->test);
    $this->travel(31)->minutes();
    $this->postJson("/api/v1/me/psychometrics/attempts/{$abandoned}/submit")->assertStatus(409);

    $retry = startAttempt($this->test);

    expect($retry)->not->toBe($abandoned);
});

it('expires an attempt that ran past the time limit and refuses the submit', function (): void {
    actAsFreshCandidate();
    $this->test->update(['time_limit_minutes' => 30]);

    $attemptId = startAttempt($this->test);

    $this->travel(31)->minutes();

    $this->postJson("/api/v1/me/psychometrics/attempts/{$attemptId}/submit")
        ->assertStatus(409);

    $attempt = PsychometricAttempt::findOrFail($attemptId);

    expect($attempt->status)->toBe(AttemptStatus::Expired);
    expect($attempt->result)->toBeNull();
});

it('refuses to save answers once the time limit passed', function (): void {
    actAsFreshCandidate();
    $this->test->update(['time_limit_minutes' => 30]);

    $attemptId = startAttempt($this->test);
    $question = PsychometricQuestion::where('psychometric_test_id', $this->test->id)->firstOrFail();

    $this->travel(31)->minutes();

    $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => [[
            'question_id' => $question->id,
            'option_id' => optionScoring($question, 3)->id,
        ]],
    ])->assertStatus(409);

    expect(PsychometricAnswer::where('psychometric_attempt_id', $attemptId)->count())->toBe(0);
});

it('does not resume an overdue attempt as if it were still open', function (): void {
    actAsFreshCandidate();
    $this->test->update(['time_limit_minutes' => 30, 'max_attempts' => 2]);

    $first = startAttempt($this->test);

    $this->travel(31)->minutes();

    // El intento vencido se cierra y se entrega uno nuevo, consumiendo cupo.
    $second = startAttempt($this->test);

    expect($second)->not->toBe($first);
    expect(PsychometricAttempt::findOrFail($first)->status)->toBe(AttemptStatus::Expired);
});
