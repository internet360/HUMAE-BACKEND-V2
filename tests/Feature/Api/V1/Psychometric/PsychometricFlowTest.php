<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateProfile;
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
});

function actAsCandidate(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    return $user;
}

it('lists active psychometric tests with question count', function (): void {
    actAsCandidate();

    $response = $this->getJson('/api/v1/me/psychometrics/tests');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.code', 'big-five-25')
        ->assertJsonPath('data.0.question_count', 25);
});

it('starts a new attempt and auto-creates candidate profile', function (): void {
    $user = actAsCandidate();
    $test = PsychometricTest::where('code', 'big-five-25')->firstOrFail();

    $response = $this->postJson('/api/v1/me/psychometrics/attempts', [
        'test_id' => $test->id,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.test_id', $test->id);

    expect(CandidateProfile::where('user_id', $user->id)->count())->toBe(1);
});

it('resumes an in-progress attempt instead of creating a duplicate', function (): void {
    actAsCandidate();
    $test = PsychometricTest::where('code', 'big-five-25')->firstOrFail();

    $first = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])->json('data.id');
    $second = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])->json('data.id');

    expect($first)->toBe($second);
});

it('saves partial answers and submits with scoring', function (): void {
    actAsCandidate();
    $test = PsychometricTest::where('code', 'big-five-25')->firstOrFail();

    $attemptId = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])
        ->json('data.id');

    // Responde con score=4 a todas las preguntas usando la opción con score=4
    $questions = PsychometricQuestion::where('psychometric_test_id', $test->id)->get();
    $answers = [];
    foreach ($questions as $q) {
        $option = PsychometricQuestionOption::where('psychometric_question_id', $q->id)
            ->where('score', 4)
            ->first();
        $answers[] = [
            'question_id' => $q->id,
            'option_id' => $option->id,
        ];
    }

    $save = $this->patchJson("/api/v1/me/psychometrics/attempts/{$attemptId}/answers", [
        'answers' => $answers,
    ]);
    $save->assertOk();

    $submit = $this->postJson("/api/v1/me/psychometrics/attempts/{$attemptId}/submit");
    $submit
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.result.passed', false); // sin passing_score, queda false
    expect($submit->json('data.result.dimension_scores'))->toBeArray()
        ->and(array_keys($submit->json('data.result.dimension_scores')))->toContain(
            'extraversion',
            'amabilidad',
            'responsabilidad',
            'neuroticismo',
            'apertura',
        );
});

it('is idempotent on submit', function (): void {
    actAsCandidate();
    $test = PsychometricTest::where('code', 'big-five-25')->firstOrFail();

    $attemptId = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])
        ->json('data.id');

    $this->postJson("/api/v1/me/psychometrics/attempts/{$attemptId}/submit")->assertOk();
    $second = $this->postJson("/api/v1/me/psychometrics/attempts/{$attemptId}/submit");
    $second->assertOk();

    // Solo debe existir un PsychometricResult
    $attempt = PsychometricAttempt::find($attemptId);
    expect($attempt->result()->count())->toBe(1);
});

it('blocks other users from viewing an attempt', function (): void {
    $userA = actAsCandidate();
    $test = PsychometricTest::where('code', 'big-five-25')->firstOrFail();
    $attemptId = $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])
        ->json('data.id');

    $userB = User::factory()->create();
    $userB->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($userB);

    $this->getJson("/api/v1/me/psychometrics/attempts/{$attemptId}")->assertStatus(404);
    $this->postJson("/api/v1/me/psychometrics/attempts/{$attemptId}/submit")->assertStatus(404);
});

it('rejects unauthenticated access', function (): void {
    $this->getJson('/api/v1/me/psychometrics/tests')->assertStatus(401);
});
