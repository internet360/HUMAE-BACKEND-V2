<?php

declare(strict_types=1);

use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricResult;
use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsPsychoAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Admin->value);
    Sanctum::actingAs($user);

    return $user;
}

function actAsRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

/** Marca la prueba como "en uso" creando un intento. */
function freezeTest(PsychometricTest $test): PsychometricAttempt
{
    return PsychometricAttempt::factory()->create(['psychometric_test_id' => $test->id]);
}

// ── Autorización ────────────────────────────────────────────────────────────

it('lets an admin create a psychometric test', function (): void {
    actAsPsychoAdmin();

    $response = $this->postJson('/api/v1/admin/psychometrics/tests', [
        'code' => 'disc-basico',
        'name' => 'DISC básico',
        'category' => 'personalidad',
        'max_attempts' => 2,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.code', 'disc-basico')
        ->assertJsonPath('data.max_attempts', 2)
        ->assertJsonPath('data.is_in_use', false);

    expect(PsychometricTest::where('code', 'disc-basico')->exists())->toBeTrue();
});

it('refuses authoring to roles without psychometric.manage', function (string $role): void {
    actAsRole($role);

    $this->postJson('/api/v1/admin/psychometrics/tests', [
        'code' => 'intruso',
        'name' => 'No debería crearse',
    ])->assertStatus(403);

    expect(PsychometricTest::where('code', 'intruso')->exists())->toBeFalse();
})->with([
    'candidato' => [UserRole::Candidate->value],
    'reclutador' => [UserRole::Recruiter->value],
    'empresa' => [UserRole::CompanyUser->value],
]);

it('lists inactive tests too, unlike the candidate endpoint', function (): void {
    actAsPsychoAdmin();
    PsychometricTest::factory()->create(['code' => 'apagada', 'is_active' => false]);

    $this->getJson('/api/v1/admin/psychometrics/tests')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'apagada');
});

// ── Estructura completa ─────────────────────────────────────────────────────

it('builds a full test structure: section, question and scored options', function (): void {
    actAsPsychoAdmin();
    $test = PsychometricTest::factory()->create();

    $sectionId = $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/sections", [
        'code' => 'razonamiento',
        'name' => 'Razonamiento',
    ])->assertCreated()->json('data.id');

    $questionId = $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/questions", [
        'section_id' => $sectionId,
        'type' => QuestionType::Likert5->value,
        'prompt' => '¿Te resulta fácil iniciar una conversación?',
        'dimension' => 'extraversion',
        'is_reverse_scored' => true,
        'weight' => 2,
    ])->assertCreated()
        ->assertJsonPath('data.dimension', 'extraversion')
        ->assertJsonPath('data.is_reverse_scored', true)
        ->json('data.id');

    $this->postJson("/api/v1/admin/psychometrics/questions/{$questionId}/options", [
        'label' => 'Muy exacto',
        'value' => '5',
        'score' => 5,
    ])->assertCreated()->assertJsonPath('data.score', 5);

    $question = PsychometricQuestion::findOrFail($questionId);

    expect($question->psychometric_test_section_id)->toBe($sectionId);
    expect($question->weight)->toBe(2);
    expect($question->options()->count())->toBe(1);
});

it('rejects a question pointing at a section from another test', function (): void {
    actAsPsychoAdmin();

    $test = PsychometricTest::factory()->create();
    $otherTest = PsychometricTest::factory()->create();
    $foreignSection = PsychometricTestSection::factory()->create([
        'psychometric_test_id' => $otherTest->id,
    ]);

    $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/questions", [
        'section_id' => $foreignSection->id,
        'type' => QuestionType::Likert5->value,
        'prompt' => 'Pregunta con sección ajena',
    ])->assertStatus(422)->assertJsonValidationErrors('section_id');
});

it('refuses a question type the scoring service cannot grade', function (): void {
    actAsPsychoAdmin();
    $test = PsychometricTest::factory()->create();

    $response = $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/questions", [
        'type' => QuestionType::Rank->value,
        'prompt' => 'Ordená estas cinco afirmaciones',
    ]);

    // `rank` existe en el enum pero el modelo de respuestas no puede representar
    // un orden (un `option_id` por pregunta, sin posición), así que el ítem
    // valdría 0 en silencio y arrastraría su dimensión hacia abajo.
    $response->assertStatus(422)->assertJsonValidationErrors('type');
    expect($test->questions()->count())->toBe(0);
});

it('normalises the dimension key so scoring cannot split one dimension in two', function (): void {
    actAsPsychoAdmin();
    $test = PsychometricTest::factory()->create();

    $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/questions", [
        'type' => QuestionType::Likert5->value,
        'prompt' => 'Dimensión con mayúsculas y acento',
        'dimension' => 'Extraversión',
    ])->assertStatus(422)->assertJsonValidationErrors('dimension');
});

// ── Congelamiento ───────────────────────────────────────────────────────────

it('freezes the structure once the test has attempts', function (): void {
    actAsPsychoAdmin();

    $test = PsychometricTest::factory()->create();
    $section = PsychometricTestSection::factory()->create(['psychometric_test_id' => $test->id]);
    $question = PsychometricQuestion::factory()->create(['psychometric_test_id' => $test->id]);
    $option = PsychometricQuestionOption::factory()->create([
        'psychometric_question_id' => $question->id,
        'score' => 3,
    ]);

    freezeTest($test);

    $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/questions", [
        'type' => QuestionType::Likert5->value,
        'prompt' => 'Nueva',
    ])->assertStatus(409);

    $this->patchJson("/api/v1/admin/psychometrics/questions/{$question->id}", [
        'prompt' => 'Editada',
    ])->assertStatus(409);

    $this->deleteJson("/api/v1/admin/psychometrics/questions/{$question->id}")->assertStatus(409);

    $this->patchJson("/api/v1/admin/psychometrics/options/{$option->id}", [
        'score' => 99,
    ])->assertStatus(409);

    $this->deleteJson("/api/v1/admin/psychometrics/sections/{$section->id}")->assertStatus(409);

    // Nada cambió.
    expect($question->fresh()->prompt)->not->toBe('Editada');
    expect($option->fresh()->score)->toBe(3);
    expect($test->questions()->count())->toBe(1);
});

it('keeps cosmetic fields editable on an in-use test but refuses the scoring ones', function (): void {
    actAsPsychoAdmin();

    $test = PsychometricTest::factory()->create([
        'code' => 'original',
        'name' => 'Nombre viejo',
        'passing_score' => 10,
    ]);
    freezeTest($test);

    $response = $this->patchJson("/api/v1/admin/psychometrics/tests/{$test->id}", [
        'name' => 'Nombre nuevo',
        'is_active' => false,
        'max_attempts' => 5,
        'passing_score' => 999,
        'code' => 'codigo-nuevo',
    ]);

    $response->assertOk();

    $fresh = $test->fresh();

    // Aplicado: no cambia cómo se calificó nada.
    expect($fresh->name)->toBe('Nombre nuevo');
    expect($fresh->is_active)->toBeFalse();
    expect($fresh->max_attempts)->toBe(5);

    // Rechazado: redefiniría resultados ya emitidos o la identidad de la prueba.
    expect($fresh->passing_score)->toBe(10);
    expect($fresh->code)->toBe('original');

    // Y se dice, no se ignora en silencio.
    expect($response->json('message'))->toContain('passing_score')
        ->and($response->json('message'))->toContain('code');
});

it('refuses to delete a test that has attempts and suggests deactivating', function (): void {
    actAsPsychoAdmin();
    $test = PsychometricTest::factory()->create();
    freezeTest($test);

    $response = $this->deleteJson("/api/v1/admin/psychometrics/tests/{$test->id}");

    $response->assertStatus(409);
    expect($response->json('message'))->toContain('Desactívala');
    expect(PsychometricTest::find($test->id))->not->toBeNull();
});

it('deletes a test that was never taken', function (): void {
    actAsPsychoAdmin();
    $test = PsychometricTest::factory()->create();
    PsychometricQuestion::factory()->create(['psychometric_test_id' => $test->id]);

    $this->deleteJson("/api/v1/admin/psychometrics/tests/{$test->id}")->assertStatus(204);

    expect(PsychometricTest::find($test->id))->toBeNull();
    expect(PsychometricQuestion::where('psychometric_test_id', $test->id)->count())->toBe(0);
});

// ── Anular un intento (vía de reparación de soporte) ────────────────────────

it('gives the candidate their slot back when an attempt is voided', function (): void {
    $candidate = User::factory()->create();
    $candidate->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create(['user_id' => $candidate->id]);

    $test = PsychometricTest::factory()->create(['is_active' => true, 'max_attempts' => 1]);
    $attempt = PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Completed,
    ]);

    // Antes de anular, el cupo está agotado.
    Sanctum::actingAs($candidate);
    $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])
        ->assertStatus(409);

    actAsPsychoAdmin();
    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", [
        'reason' => 'El candidato reportó que se cortó la conexión a mitad.',
    ])->assertOk();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Cancelled);

    // Y ahora sí puede volver a responder: ningún candado cuenta `cancelled`.
    Sanctum::actingAs($candidate);
    $this->postJson('/api/v1/me/psychometrics/attempts', ['test_id' => $test->id])
        ->assertCreated();
});

it('records who voided the attempt and why', function (): void {
    $admin = actAsPsychoAdmin();
    $attempt = PsychometricAttempt::factory()->create(['status' => AttemptStatus::Completed]);

    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", [
        'reason' => 'Respuestas en patrón: todas la misma opción en 25 ítems.',
    ])->assertOk();

    $fresh = $attempt->fresh();

    // Anular la medición de una persona sin dejar rastro de quién y por qué
    // volvería la auditoría un registro inútil.
    expect($fresh->cancelled_by)->toBe($admin->id);
    expect($fresh->cancelled_reason)->toContain('patrón');
    expect($fresh->cancelled_at)->not->toBeNull();
});

it('requires a reason to void an attempt', function (): void {
    actAsPsychoAdmin();
    $attempt = PsychometricAttempt::factory()->create(['status' => AttemptStatus::Completed]);

    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Completed);
});

it('hides a voided result from the recruiter without deleting it', function (): void {
    $profile = CandidateProfile::factory()->create();
    $test = PsychometricTest::factory()->create();
    $attempt = PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Completed,
    ]);
    PsychometricResult::factory()->create(['psychometric_attempt_id' => $attempt->id]);

    actAsPsychoAdmin();
    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", [
        'reason' => 'Se anula por pedido del candidato.',
    ])->assertOk();

    // El reclutador ya no lo ve: `scoredAttempts()` filtra por `completed`.
    actAsRole(UserRole::Recruiter->value);
    $this->getJson("/api/v1/directory/candidates/{$profile->id}/psychometrics")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Pero la fila y su resultado siguen ahí, para auditoría.
    expect(PsychometricResult::where('psychometric_attempt_id', $attempt->id)->exists())
        ->toBeTrue();
});

it('refuses to void the same attempt twice', function (): void {
    actAsPsychoAdmin();
    $attempt = PsychometricAttempt::factory()->create(['status' => AttemptStatus::Completed]);

    $payload = ['reason' => 'Primera anulación, con motivo suficiente.'];

    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", $payload)
        ->assertOk();
    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", $payload)
        ->assertStatus(409);
});

it('keeps voiding out of reach for recruiters', function (): void {
    actAsRole(UserRole::Recruiter->value);
    $attempt = PsychometricAttempt::factory()->create(['status' => AttemptStatus::Completed]);

    $this->postJson("/api/v1/admin/psychometrics/attempts/{$attempt->id}/cancel", [
        'reason' => 'Intento de anulación sin permiso.',
    ])->assertStatus(403);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Completed);
});

// ── Versionado ──────────────────────────────────────────────────────────────

it('duplicates a frozen test into an inactive editable version', function (): void {
    actAsPsychoAdmin();

    $test = PsychometricTest::factory()->create(['code' => 'v1', 'is_active' => true]);
    $section = PsychometricTestSection::factory()->create([
        'psychometric_test_id' => $test->id,
        'code' => 'seccion-a',
    ]);
    $question = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'psychometric_test_section_id' => $section->id,
        'dimension' => 'extraversion',
    ]);
    PsychometricQuestionOption::factory()->count(3)->create([
        'psychometric_question_id' => $question->id,
    ]);

    freezeTest($test);

    $copyId = $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/duplicate", [
        'code' => 'v2',
        'name' => 'Versión 2',
    ])->assertCreated()
        ->assertJsonPath('data.code', 'v2')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_in_use', false)
        ->json('data.id');

    $copy = PsychometricTest::with('sections', 'questions.options')->findOrFail($copyId);

    expect($copy->sections)->toHaveCount(1);
    expect($copy->questions)->toHaveCount(1);
    expect($copy->questions[0]->options)->toHaveCount(3);
    expect($copy->questions[0]->dimension)->toBe('extraversion');

    // La pregunta copiada apunta a la sección COPIADA, no a la original.
    expect($copy->questions[0]->psychometric_test_section_id)
        ->toBe($copy->sections[0]->id)
        ->and($copy->questions[0]->psychometric_test_section_id)->not->toBe($section->id);

    // Y la copia sí se puede editar: es el punto de duplicar.
    $this->patchJson("/api/v1/admin/psychometrics/questions/{$copy->questions[0]->id}", [
        'prompt' => 'Ítem revisado',
    ])->assertOk();

    // La original quedó intacta.
    expect($test->fresh()->is_active)->toBeTrue();
    expect($question->fresh()->prompt)->not->toBe('Ítem revisado');
});

it('refuses to duplicate onto an existing code', function (): void {
    actAsPsychoAdmin();
    $test = PsychometricTest::factory()->create(['code' => 'v1']);
    PsychometricTest::factory()->create(['code' => 'ya-existe']);

    $this->postJson("/api/v1/admin/psychometrics/tests/{$test->id}/duplicate", [
        'code' => 'ya-existe',
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

// ── Separación de recursos ──────────────────────────────────────────────────

it('exposes scoring fields to the admin that the candidate resource hides', function (): void {
    actAsPsychoAdmin();

    $test = PsychometricTest::factory()->create(['is_active' => true]);
    $question = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'dimension' => 'apertura',
        'is_reverse_scored' => true,
    ]);
    PsychometricQuestionOption::factory()->create([
        'psychometric_question_id' => $question->id,
        'score' => 4,
        'is_correct' => true,
    ]);

    $admin = $this->getJson("/api/v1/admin/psychometrics/tests/{$test->id}")->assertOk();

    expect($admin->json('data.questions.0.dimension'))->toBe('apertura');
    expect($admin->json('data.questions.0.options.0.score'))->toBe(4);
    expect($admin->json('data.questions.0.options.0.is_correct'))->toBeTrue();

    // El mismo árbol, visto por el candidato: sin clave de calificación.
    actAsRole(UserRole::Candidate->value);

    $candidate = $this->getJson('/api/v1/me/psychometrics/tests')->assertOk();

    expect($candidate->json('data.0.questions.0'))->not->toHaveKey('dimension');
    expect($candidate->json('data.0.questions.0'))->not->toHaveKey('is_reverse_scored');
    expect($candidate->json('data.0.questions.0.options.0'))->not->toHaveKey('score');
    expect($candidate->json('data.0.questions.0.options.0'))->not->toHaveKey('is_correct');
});
