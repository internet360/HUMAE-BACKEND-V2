<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\AttemptStatus;
use App\Enums\CompanyMemberRole;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use App\Models\PsychometricResult;
use App\Models\PsychometricTest;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function visUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Candidato con un intento calificado.
 *
 * @return array{profile: CandidateProfile, attempt: PsychometricAttempt}
 */
function candidateWithResult(array $resultAttrs = []): array
{
    $profile = CandidateProfile::factory()->create([
        'first_name' => 'Mariana',
        'last_name' => 'Quiroga',
    ]);

    $test = PsychometricTest::factory()->create([
        'code' => 'big-five-vis',
        'name' => 'Big Five',
        'category' => 'personalidad',
    ]);

    $attempt = PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Completed,
        'submitted_at' => now()->subDay(),
    ]);

    PsychometricResult::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'dimension_scores' => ['extraversion' => 18.0, 'apertura' => 21.0],
        'summary' => 'Dimensión más alta: apertura.',
        'recommendations' => 'Nota interna de HUMAE.',
        'percentile' => 72.5,
        ...$resultAttrs,
    ]);

    return ['profile' => $profile, 'attempt' => $attempt];
}

/**
 * Asignación de un candidato a la vacante de una empresa, en la etapa dada.
 *
 * @return array{assignment: VacancyAssignment, companyUser: User}
 */
function assignedToCompany(CandidateProfile $profile, AssignmentStage $stage): array
{
    $company = Company::factory()->create();
    $companyUser = visUser(UserRole::CompanyUser->value);

    CompanyMember::factory()->create([
        'company_id' => $company->id,
        'user_id' => $companyUser->id,
        'role' => CompanyMemberRole::Owner,
    ]);

    $vacancy = Vacancy::factory()->create(['company_id' => $company->id]);

    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
        'stage' => $stage,
    ]);

    return ['assignment' => $assignment, 'companyUser' => $companyUser];
}

// ── Reclutador ──────────────────────────────────────────────────────────────

it('shows the psychometric profile to a recruiter in the candidate directory', function (): void {
    ['profile' => $profile] = candidateWithResult();

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $response = $this->getJson("/api/v1/directory/candidates/{$profile->id}/psychometrics");

    $response->assertOk()
        ->assertJsonPath('data.0.test.code', 'big-five-vis')
        ->assertJsonPath('data.0.result.dimension_scores.apertura', 21)
        ->assertJsonPath('data.0.result.summary', 'Dimensión más alta: apertura.');

    // HUMAE sí ve sus propias anotaciones internas.
    expect($response->json('data.0.result.recommendations'))->toBe('Nota interna de HUMAE.');
    expect($response->json('data.0.result.percentile'))->toBe(72.5);
});

it('hides unscored attempts from the recruiter view', function (): void {
    ['profile' => $profile] = candidateWithResult();
    $test = PsychometricTest::where('code', 'big-five-vis')->firstOrFail();

    // Uno en curso y uno vencido: ninguno tiene resultado que interpretar.
    PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::InProgress,
    ]);
    PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Expired,
    ]);

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $this->getJson("/api/v1/directory/candidates/{$profile->id}/psychometrics")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('keeps the candidate directory closed to the company client', function (): void {
    ['profile' => $profile] = candidateWithResult();

    Sanctum::actingAs(visUser(UserRole::CompanyUser->value));

    // §6: la empresa no navega el directorio, ni para psicométricos.
    $this->getJson("/api/v1/directory/candidates/{$profile->id}/psychometrics")
        ->assertStatus(403);
});

// ── Hoja de respuestas (ítem por ítem) ──────────────────────────────────────

it('gives HUMAE the item-by-item answer sheet with the points each one contributed', function (): void {
    ['attempt' => $attempt] = candidateWithResult();
    $test = PsychometricTest::where('code', 'big-five-vis')->firstOrFail();

    $question = PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'prompt' => '¿Te resulta fácil iniciar una conversación?',
        'dimension' => 'extraversion',
        'is_reverse_scored' => true,
        'weight' => 2,
        'type' => QuestionType::Likert5,
    ]);
    $option = PsychometricQuestionOption::factory()->create([
        'psychometric_question_id' => $question->id,
        'label' => 'Inexacto',
        'score' => 2,
    ]);
    PsychometricAnswer::factory()->create([
        'psychometric_attempt_id' => $attempt->id,
        'psychometric_question_id' => $question->id,
        'psychometric_question_option_id' => $option->id,
    ]);

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $response = $this->getJson("/api/v1/psychometrics/attempts/{$attempt->id}/answers");

    $response->assertOk()
        ->assertJsonPath('data.items.0.prompt', '¿Te resulta fácil iniciar una conversación?')
        ->assertJsonPath('data.items.0.chosen.label', 'Inexacto')
        ->assertJsonPath('data.items.0.chosen.points', 2);

    // Invertida (6-2=4) y con peso 2 → 8. Sale del MISMO método que sumó el
    // total, no de una fórmula copiada. Se castea porque `json_encode` serializa
    // el float 8.0 como `8`.
    expect((float) $response->json('data.items.0.awarded_points'))->toBe(8.0);
    expect($response->json('data.items.0.is_reverse_scored'))->toBeTrue();
});

it('lists unanswered items instead of hiding them', function (): void {
    ['attempt' => $attempt] = candidateWithResult();
    $test = PsychometricTest::where('code', 'big-five-vis')->firstOrFail();

    // Pregunta sin respuesta: es justo la que hay que ver al auditar un puntaje
    // bajo. Recorriendo respuestas en lugar de preguntas, desaparecería.
    PsychometricQuestion::factory()->create([
        'psychometric_test_id' => $test->id,
        'prompt' => 'Ítem que quedó en blanco',
    ]);

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $response = $this->getJson("/api/v1/psychometrics/attempts/{$attempt->id}/answers")
        ->assertOk();

    $blank = collect($response->json('data.items'))
        ->firstWhere('prompt', 'Ítem que quedó en blanco');

    expect($blank['answered'])->toBeFalse();
    expect($blank['chosen'])->toBeNull();
    expect($blank['awarded_points'])->toBeNull();
});

it('keeps the answer sheet away from the company client', function (): void {
    ['profile' => $profile, 'attempt' => $attempt] = candidateWithResult();
    ['companyUser' => $companyUser] = assignedToCompany($profile, AssignmentStage::Presented);

    Sanctum::actingAs($companyUser);

    // La empresa ve el agregado, no el cuestionario respondido: publicarlo lo
    // vuelve reconstruible.
    $this->getJson("/api/v1/psychometrics/attempts/{$attempt->id}/answers")
        ->assertStatus(403);
});

// ── Interpretación firmada ──────────────────────────────────────────────────

it('records the recruiter interpretation with a signature', function (): void {
    ['profile' => $profile, 'attempt' => $attempt] = candidateWithResult([
        'recommendations' => null,
        'reviewed_at' => null,
    ]);

    $recruiter = visUser(UserRole::Recruiter->value);
    Sanctum::actingAs($recruiter);

    $this->patchJson("/api/v1/psychometrics/attempts/{$attempt->id}/review", [
        'recommendations' => 'Perfil sólido para roles de atención a cliente.',
    ])->assertOk();

    $result = $attempt->fresh()->result;

    // Las tres columnas existían desde la primera migración sin que nada las
    // escribiera: la UI las mostraba siempre vacías.
    expect($result->recommendations)->toContain('atención a cliente');
    expect($result->reviewed_by)->toBe($recruiter->id);
    expect($result->reviewed_at)->not->toBeNull();
    expect($profile->id)->toBeInt();
});

it('does not touch the measurement when the interpretation is saved', function (): void {
    ['attempt' => $attempt] = candidateWithResult();
    $before = $attempt->result;

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $this->patchJson("/api/v1/psychometrics/attempts/{$attempt->id}/review", [
        'recommendations' => 'Otra lectura del mismo resultado.',
    ])->assertOk();

    $after = $attempt->fresh()->result;

    // Anotar es interpretar, no re-medir.
    expect((float) $after->total_score)->toBe((float) $before->total_score);
    expect($after->dimension_scores)->toBe($before->dimension_scores);
});

it('lets an empty payload clear an interpretation written by mistake', function (): void {
    ['attempt' => $attempt] = candidateWithResult();

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $this->patchJson("/api/v1/psychometrics/attempts/{$attempt->id}/review", [
        'recommendations' => null,
    ])->assertOk();

    expect($attempt->fresh()->result->recommendations)->toBeNull();
});

it('refuses to annotate an attempt with no result', function (): void {
    $profile = CandidateProfile::factory()->create();
    $test = PsychometricTest::factory()->create();
    $attempt = PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Expired,
    ]);

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    $this->patchJson("/api/v1/psychometrics/attempts/{$attempt->id}/review", [
        'recommendations' => 'Nota sobre algo que no se midió.',
    ])->assertStatus(409);
});

it('keeps the interpretation out of the company payload', function (): void {
    ['profile' => $profile, 'attempt' => $attempt] = candidateWithResult();
    ['assignment' => $assignment, 'companyUser' => $companyUser] =
        assignedToCompany($profile, AssignmentStage::Presented);

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));
    $this->patchJson("/api/v1/psychometrics/attempts/{$attempt->id}/review", [
        'recommendations' => 'Juicio interno que la empresa no debe leer.',
    ])->assertOk();

    Sanctum::actingAs($companyUser);
    $payload = $this->getJson("/api/v1/me/company/assignments/{$assignment->id}/psychometrics")
        ->assertOk()
        ->json('data.0.result');

    expect($payload)->not->toHaveKey('recommendations');
});

it('will not let the company write an interpretation', function (): void {
    ['profile' => $profile, 'attempt' => $attempt] = candidateWithResult();
    ['companyUser' => $companyUser] = assignedToCompany($profile, AssignmentStage::Presented);

    Sanctum::actingAs($companyUser);

    $this->patchJson("/api/v1/psychometrics/attempts/{$attempt->id}/review", [
        'recommendations' => 'La empresa opinando sobre el candidato.',
    ])->assertStatus(403);
});

// ── Empresa cliente ─────────────────────────────────────────────────────────

it('shows the psychometric profile of a presented candidate to its company', function (): void {
    ['profile' => $profile] = candidateWithResult();
    ['assignment' => $assignment, 'companyUser' => $companyUser] =
        assignedToCompany($profile, AssignmentStage::Presented);

    Sanctum::actingAs($companyUser);

    $response = $this->getJson("/api/v1/me/company/assignments/{$assignment->id}/psychometrics");

    $response->assertOk()
        ->assertJsonPath('data.0.test.name', 'Big Five')
        ->assertJsonPath('data.0.result.dimension_scores.apertura', 21);
});

it('never leaks HUMAE internal annotations to the company', function (): void {
    ['profile' => $profile] = candidateWithResult();
    ['assignment' => $assignment, 'companyUser' => $companyUser] =
        assignedToCompany($profile, AssignmentStage::Finalist);

    Sanctum::actingAs($companyUser);

    $result = $this->getJson("/api/v1/me/company/assignments/{$assignment->id}/psychometrics")
        ->assertOk()
        ->json('data.0');

    expect($result['result'])->not->toHaveKey('recommendations');
    expect($result['result'])->not->toHaveKey('percentile');
    expect($result)->not->toHaveKey('status');
    expect($result['test'])->not->toHaveKey('code');
});

it('refuses the psychometric profile of a candidate the company was never shown', function (
    AssignmentStage $hiddenStage,
): void {
    ['profile' => $profile] = candidateWithResult();
    ['assignment' => $assignment, 'companyUser' => $companyUser] =
        assignedToCompany($profile, $hiddenStage);

    Sanctum::actingAs($companyUser);

    // `sourced` es la lista interna del reclutador y `rejected` sus descartes:
    // ninguno de los dos sale del equipo, y el perfil psicométrico menos.
    $this->getJson("/api/v1/me/company/assignments/{$assignment->id}/psychometrics")
        ->assertStatus(403);
})->with([
    'sourced — lista interna' => [AssignmentStage::Sourced],
    'rejected — descarte interno' => [AssignmentStage::Rejected],
]);

it('refuses an assignment that belongs to another company', function (): void {
    ['profile' => $profile] = candidateWithResult();
    ['assignment' => $assignment] = assignedToCompany($profile, AssignmentStage::Presented);

    // Otra empresa, con su propio usuario.
    ['companyUser' => $outsider] = assignedToCompany(
        CandidateProfile::factory()->create(),
        AssignmentStage::Presented,
    );

    Sanctum::actingAs($outsider);

    $this->getJson("/api/v1/me/company/assignments/{$assignment->id}/psychometrics")
        ->assertStatus(403);
});

// ── Consola admin ───────────────────────────────────────────────────────────

it('lets an admin find a candidate and see every attempt, scored or not', function (): void {
    ['profile' => $profile] = candidateWithResult();
    $test = PsychometricTest::where('code', 'big-five-vis')->firstOrFail();

    PsychometricAttempt::factory()->create([
        'candidate_profile_id' => $profile->id,
        'psychometric_test_id' => $test->id,
        'status' => AttemptStatus::Expired,
    ]);

    Sanctum::actingAs(visUser(UserRole::Admin->value));

    $search = $this->getJson('/api/v1/admin/psychometrics/candidates?q=Quiroga')->assertOk();

    expect($search->json('data.0.id'))->toBe($profile->id);
    expect($search->json('data.0.attempt_count'))->toBe(2);

    $detail = $this->getJson("/api/v1/admin/psychometrics/candidates/{$profile->id}")->assertOk();

    // El admin sí ve el vencido: es lo que hace falta para atender un reclamo
    // de "rendí y no aparece".
    expect($detail->json('data.candidate.id'))->toBe($profile->id);
    expect($detail->json('data.attempts'))->toHaveCount(2);
    expect(collect($detail->json('data.attempts'))->pluck('status')->all())
        ->toContain('expired')
        ->toContain('completed');
});

it('finds a candidate by email too', function (): void {
    ['profile' => $profile] = candidateWithResult();
    $profile->user->update(['email' => 'mariana.quiroga@example.com']);

    Sanctum::actingAs(visUser(UserRole::Admin->value));

    $this->getJson('/api/v1/admin/psychometrics/candidates?q=mariana.quiroga')
        ->assertOk()
        ->assertJsonPath('data.0.id', $profile->id);
});

it('lists only candidates who actually sat a test when no term is given', function (): void {
    ['profile' => $withResult] = candidateWithResult();
    CandidateProfile::factory()->create(['first_name' => 'Nunca', 'last_name' => 'Rindió']);

    Sanctum::actingAs(visUser(UserRole::Admin->value));

    $response = $this->getJson('/api/v1/admin/psychometrics/candidates')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($withResult->id);
});

it('keeps the admin console closed to recruiters', function (): void {
    ['profile' => $profile] = candidateWithResult();

    Sanctum::actingAs(visUser(UserRole::Recruiter->value));

    // El reclutador lee resultados por el directorio, no por la consola del
    // módulo: administrar el instrumento es de admin.
    $this->getJson('/api/v1/admin/psychometrics/candidates')->assertStatus(403);
    $this->getJson("/api/v1/admin/psychometrics/candidates/{$profile->id}")->assertStatus(403);
});
