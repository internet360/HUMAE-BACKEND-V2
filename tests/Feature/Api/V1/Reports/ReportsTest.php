<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CandidateState;
use App\Enums\InterviewState;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\DirectoryFavorite;
use App\Models\Interview;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actAsReportStaff(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);
    Sanctum::actingAs($user);

    return $user;
}

function reportsMakeActiveMembership(User $user): Membership
{
    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);
    $plan = MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);

    return Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDays(10),
        'expires_at' => now()->addDays(150),
    ]);
}

it('returns candidates registered with breakdown', function (): void {
    actAsReportStaff();

    CandidateProfile::factory()->count(3)->create([
        'state' => CandidateState::Activo->value,
    ]);
    CandidateProfile::factory()->count(2)->create([
        'state' => CandidateState::RegistroIncompleto->value,
    ]);

    $response = $this->getJson('/api/v1/admin/reports/candidates-registered');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 5);

    $byState = $response->json('data.by_state');
    expect($byState)->toHaveKeys(['activo', 'registro_incompleto']);
});

it('returns active memberships aggregates', function (): void {
    actAsReportStaff();

    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    reportsMakeActiveMembership($user);

    $response = $this->getJson('/api/v1/admin/reports/active-memberships');

    $response->assertOk()->assertJsonPath('data.active', 1);
    expect($response->json('data.by_plan'))->toHaveKey('candidate_6m');
});

it('returns payments aggregates', function (): void {
    actAsReportStaff();

    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);

    Payment::factory()->create([
        'status' => PaymentStatus::Succeeded,
        'amount' => 499,
        'net_amount' => 499,
        'salary_currency_id' => $currency->id,
        'paid_at' => now()->subDays(5),
    ]);
    Payment::factory()->create([
        'status' => PaymentStatus::Failed,
        'amount' => 499,
        'net_amount' => 499,
        'salary_currency_id' => $currency->id,
    ]);

    $response = $this->getJson('/api/v1/admin/reports/payments');

    $response->assertOk()
        ->assertJsonPath('data.count_succeeded', 1)
        ->assertJsonPath('data.count_failed', 1)
        ->assertJsonPath('data.total_paid', 499);
});

it('lists vacancies grouped by state with all states present', function (): void {
    actAsReportStaff();

    $company = Company::factory()->create();
    Vacancy::factory()->create(['company_id' => $company->id, 'state' => VacancyState::Borrador]);
    Vacancy::factory()->count(2)->create(['company_id' => $company->id, 'state' => VacancyState::Activa]);

    $response = $this->getJson('/api/v1/admin/reports/vacancies-by-state');

    $response->assertOk();
    $data = $response->json('data');
    expect($data['borrador'])->toBe(1)
        ->and($data['activa'])->toBe(2)
        ->and($data)->toHaveKey('cubierta')
        ->and($data['cubierta'])->toBe(0);
});

it('lists interviews grouped by state and day', function (): void {
    actAsReportStaff();

    $assignment = VacancyAssignment::factory()->create();
    Interview::factory()->count(2)->create([
        'vacancy_assignment_id' => $assignment->id,
        'state' => InterviewState::Propuesta,
        'scheduled_at' => now()->addDays(2),
    ]);
    Interview::factory()->create([
        'vacancy_assignment_id' => $assignment->id,
        'state' => InterviewState::Confirmada,
        'scheduled_at' => now()->addDays(3),
    ]);

    $response = $this->getJson(
        '/api/v1/admin/reports/interviews?from='.now()->subDay()->toDateString()
        .'&to='.now()->addDays(10)->toDateString()
    );

    $response->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_state.propuesta', 2)
        ->assertJsonPath('data.by_state.confirmada', 1);
});

it('returns recruiter effectiveness', function (): void {
    $recruiter = actAsReportStaff();

    VacancyAssignment::factory()->count(3)->create([
        'assigned_by' => $recruiter->id,
        'stage' => AssignmentStage::Presented,
    ]);
    VacancyAssignment::factory()->create([
        'assigned_by' => $recruiter->id,
        'stage' => AssignmentStage::Hired,
    ]);

    $response = $this->getJson('/api/v1/admin/reports/recruiter-effectiveness');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('recruiter_id', $recruiter->id);
    expect($row['assignments'])->toBe(4)
        ->and($row['hired'])->toBe(1)
        ->and($row['hire_rate'])->toBe(0.25);
});

it('returns most searched profiles via directory favorites', function (): void {
    $recruiter = actAsReportStaff();

    $popular = CandidateProfile::factory()->create(['first_name' => 'Popular']);
    $lessPopular = CandidateProfile::factory()->create(['first_name' => 'LessPopular']);

    DirectoryFavorite::create([
        'recruiter_id' => $recruiter->id,
        'candidate_profile_id' => $popular->id,
    ]);

    $another = User::factory()->create();
    $another->assignRole(UserRole::Recruiter->value);
    DirectoryFavorite::create([
        'recruiter_id' => $another->id,
        'candidate_profile_id' => $popular->id,
    ]);
    DirectoryFavorite::create([
        'recruiter_id' => $another->id,
        'candidate_profile_id' => $lessPopular->id,
    ]);

    $response = $this->getJson('/api/v1/admin/reports/most-searched-profiles');

    $response->assertOk();
    $items = $response->json('data');
    expect($items[0]['candidate_profile_id'])->toBe($popular->id)
        ->and($items[0]['times_favorited'])->toBe(2);
});

it('blocks candidate from reports', function (): void {
    $candidate = User::factory()->create();
    $candidate->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($candidate);

    $this->getJson('/api/v1/admin/reports/active-memberships')->assertStatus(403);
});

it('rejects unauthenticated reports access', function (): void {
    $this->getJson('/api/v1/admin/reports/active-memberships')->assertStatus(401);
});
