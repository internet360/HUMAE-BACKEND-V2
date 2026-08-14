<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\InterviewRequestCandidateState;
use App\Enums\InterviewRequestState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\InterviewRequest;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Notifications\InterviewRequestSubmittedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * Solicitud de entrevistas: el paso donde la selección anónima se vuelve un
 * pedido concreto a HUMAE.
 *
 * Tres cosas se prueban con más insistencia que el resto, porque son las que
 * sostienen el modelo: que los perfiles se nombren por referencia opaca y nunca
 * por id, que la solicitud NO escriba en el pipeline de HUMAE, y que la
 * respuesta siga siendo anónima incluso en la solicitud propia.
 */
const INTERVIEW_REQUESTS_URL = '/api/v1/me/company/interview-requests';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function irPlan(): MembershipPlan
{
    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);

    return MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);
}

function irCandidate(array $profile = [], bool $eligible = true): CandidateProfile
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => irPlan()->id,
        'status' => $eligible ? MembershipStatus::Active : MembershipStatus::Expired,
        'started_at' => now()->subDay(),
        'expires_at' => $eligible ? now()->addDays(100) : now()->subDay(),
    ]);

    return CandidateProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'state' => CandidateState::Activo->value,
    ], $profile));
}

function irActAsCompany(string $role = CompanyMemberRole::Owner->value): array
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::CompanyUser->value);

    $company = Company::factory()->create();
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => $role,
        'is_primary_contact' => true,
        'accepted_at' => now(),
    ]);

    Sanctum::actingAs($user);

    return [$user, $company];
}

function irPayload(array $references, array $overrides = []): array
{
    return array_merge([
        'candidate_references' => $references,
        'vacancy' => [
            'title' => 'Contador Senior',
            'description' => 'Cierre mensual y consolidación para grupo manufacturero.',
        ],
        'interview_slots' => [
            now()->addDays(3)->setTime(10, 0)->toIso8601String(),
            now()->addDays(4)->setTime(16, 0)->toIso8601String(),
        ],
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Alta
|--------------------------------------------------------------------------
*/

it('creates the vacancy, the request and the selection in one call', function (): void {
    $a = irCandidate(['headline' => 'Contadora Senior']);
    $b = irCandidate(['headline' => 'Contador Semi Senior']);
    [$user, $company] = irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([
        $a->public_reference,
        $b->public_reference,
    ]))->assertCreated();

    $request = InterviewRequest::first();

    expect($request)->not->toBeNull()
        ->and($request->company_id)->toBe($company->id)
        ->and($request->requested_by_user_id)->toBe($user->id)
        ->and($request->state)->toBe(InterviewRequestState::Pendiente)
        ->and($request->candidates()->count())->toBe(2);

    $vacancy = Vacancy::acrossCompanies()->find($request->vacancy_id);

    expect($vacancy->state)->toBe(VacancyState::Solicitada)
        ->and($vacancy->title)->toBe('Contador Senior')
        ->and($vacancy->company_id)->toBe($company->id)
        ->and($vacancy->code)->toStartWith('HUM-')
        ->and($vacancy->slug)->not->toBeNull();
});

it('never writes into HUMAE pipeline when the employer selects', function (): void {
    $a = irCandidate();
    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$a->public_reference]))
        ->assertCreated();

    // Señalar no es presentar. La asignación nace cuando HUMAE acepta el
    // perfil, no cuando el cliente lo elige.
    expect(VacancyAssignment::count())->toBe(0);

    $item = InterviewRequest::first()->candidates()->first();
    expect($item->state)->toBe(InterviewRequestCandidateState::Pendiente)
        ->and($item->vacancy_assignment_id)->toBeNull();
});

it('notifies HUMAE staff and nobody else', function (): void {
    $candidate = irCandidate();

    $recruiter = User::factory()->create();
    $recruiter->assignRole(UserRole::Recruiter->value);

    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$candidate->public_reference]))
        ->assertCreated();

    Notification::assertSentTo($recruiter, InterviewRequestSubmittedNotification::class);
    Notification::assertNotSentTo($candidate->user, InterviewRequestSubmittedNotification::class);
});

it('stores both proposed slots', function (): void {
    $candidate = irCandidate();
    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$candidate->public_reference]))
        ->assertCreated()
        ->assertJsonCount(2, 'data.proposed_slots');

    $request = InterviewRequest::first();

    expect($request->proposed_slot_1_at)->not->toBeNull()
        ->and($request->proposed_slot_2_at)->not->toBeNull()
        ->and($request->proposedSlots())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Anonimato, también en la solicitud propia
|--------------------------------------------------------------------------
*/

it('keeps the selected profiles anonymous in the response', function (): void {
    $candidate = irCandidate([
        'first_name' => 'Mariana',
        'last_name' => 'Gutierrez',
        'contact_email' => 'mariana@example.com',
        'headline' => 'Contadora Senior',
    ]);
    irActAsCompany();

    $response = $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$candidate->public_reference]))
        ->assertCreated();

    $card = $response->json('data.candidates.0.candidate');

    expect($card['reference'])->toBe($candidate->public_reference)
        ->and($card)->not->toHaveKey('first_name')
        ->and($card)->not->toHaveKey('id');

    $body = $response->getContent();
    expect($body)->not->toContain('Mariana')
        ->and($body)->not->toContain('Gutierrez')
        ->and($body)->not->toContain('mariana@example.com');
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

it('demands exactly two interview slots', function (): void {
    $candidate = irCandidate();
    irActAsCompany();

    $one = irPayload([$candidate->public_reference], [
        'interview_slots' => [now()->addDays(3)->toIso8601String()],
    ]);
    $this->postJson(INTERVIEW_REQUESTS_URL, $one)
        ->assertStatus(422)
        ->assertJsonValidationErrors('interview_slots');

    $three = irPayload([$candidate->public_reference], [
        'interview_slots' => [
            now()->addDays(3)->toIso8601String(),
            now()->addDays(4)->toIso8601String(),
            now()->addDays(5)->toIso8601String(),
        ],
    ]);
    $this->postJson(INTERVIEW_REQUESTS_URL, $three)
        ->assertStatus(422)
        ->assertJsonValidationErrors('interview_slots');
});

it('rejects slots in the past and duplicated slots', function (): void {
    $candidate = irCandidate();
    irActAsCompany();

    $past = irPayload([$candidate->public_reference], [
        'interview_slots' => [
            now()->subDay()->toIso8601String(),
            now()->addDays(4)->toIso8601String(),
        ],
    ]);
    $this->postJson(INTERVIEW_REQUESTS_URL, $past)
        ->assertStatus(422)
        ->assertJsonValidationErrors('interview_slots.0');

    $same = now()->addDays(3)->toIso8601String();
    $duplicated = irPayload([$candidate->public_reference], [
        'interview_slots' => [$same, $same],
    ]);
    $this->postJson(INTERVIEW_REQUESTS_URL, $duplicated)
        ->assertStatus(422);
});

it('demands at least one selected profile', function (): void {
    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([], ['candidate_references' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('candidate_references');
});

it('refuses a database id where a reference belongs', function (): void {
    $candidate = irCandidate();
    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([(string) $candidate->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('candidate_references.0');
});

it('refuses the whole request when a profile is no longer available', function (): void {
    $ok = irCandidate();
    $expired = irCandidate(eligible: false);
    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([
        $ok->public_reference,
        $expired->public_reference,
    ]))->assertStatus(422)
        ->assertJsonValidationErrors('candidate_references');

    // Nada a medias: ni vacante ni solicitud.
    expect(InterviewRequest::count())->toBe(0)
        ->and(Vacancy::acrossCompanies()->count())->toBe(0);
});

it('refuses HUMAE commercial terms smuggled in the vacancy payload', function (): void {
    $candidate = irCandidate();
    irActAsCompany();

    $payload = irPayload([$candidate->public_reference]);
    $payload['vacancy']['fee_percentage'] = 5;
    $payload['vacancy']['internal_notes'] = 'escrito por la empresa';
    $payload['vacancy']['company_id'] = 9999;

    $this->postJson(INTERVIEW_REQUESTS_URL, $payload)->assertCreated();

    $vacancy = Vacancy::acrossCompanies()->first();

    expect($vacancy->fee_percentage)->toBeNull()
        ->and($vacancy->internal_notes)->toBeNull()
        ->and($vacancy->company_id)->not->toBe(9999);
});

/*
|--------------------------------------------------------------------------
| Autorización y aislamiento entre empresas
|--------------------------------------------------------------------------
*/

it('locks a guest out', function (): void {
    $this->postJson(INTERVIEW_REQUESTS_URL, [])->assertUnauthorized();
    $this->getJson(INTERVIEW_REQUESTS_URL)->assertUnauthorized();
});

it('locks a candidate out', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->getJson(INTERVIEW_REQUESTS_URL)->assertForbidden();
});

it('does not let a viewer commit the company to interviews', function (): void {
    $candidate = irCandidate();
    irActAsCompany(CompanyMemberRole::Viewer->value);

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$candidate->public_reference]))
        ->assertForbidden();

    expect(InterviewRequest::count())->toBe(0);
});

it('does not show one company the requests of another', function (): void {
    $candidate = irCandidate();

    [, $companyA] = irActAsCompany();
    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$candidate->public_reference]))
        ->assertCreated();

    $foreign = InterviewRequest::acrossCompanies()->first();

    irActAsCompany();

    $this->getJson(INTERVIEW_REQUESTS_URL)
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson(INTERVIEW_REQUESTS_URL.'/'.$foreign->id)
        ->assertNotFound();
});

it('lists the requests of the caller company', function (): void {
    $candidate = irCandidate();
    irActAsCompany();

    $this->postJson(INTERVIEW_REQUESTS_URL, irPayload([$candidate->public_reference]))
        ->assertCreated();

    $this->getJson(INTERVIEW_REQUESTS_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.state', 'pendiente')
        ->assertJsonPath('data.0.vacancy.state', 'solicitada');
});
