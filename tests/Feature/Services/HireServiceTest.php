<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CandidateState;
use App\Enums\CompanyMemberRole;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\CompanyContract;
use App\Models\CompanyMember;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Notifications\AssignmentRejectedNotification;
use App\Notifications\CandidateHiredNotification;
use App\Services\HireService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function hireScenario(): array
{
    $company = Company::factory()->create();

    // Contrato firmado: `PlacementChargeService` lo exige para devengar. Es el
    // segundo candado del riesgo de facturar sin instrumento — el primero está
    // en el agendado, y se puede llegar a `hired` sin haber agendado nunca.
    CompanyContract::factory()->create(['company_id' => $company->id]);

    $owner = User::factory()->create();
    $owner->assignRole(UserRole::CompanyUser->value);
    CompanyMember::create([
        'company_id' => $company->id,
        'user_id' => $owner->id,
        'role' => CompanyMemberRole::Owner->value,
    ]);

    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::EntrevistasEnCurso,
    ]);

    $hiredProfile = CandidateProfile::factory()->create([
        'state' => CandidateState::Activo->value,
    ]);
    $hiredAssignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $hiredProfile->id,
        'stage' => AssignmentStage::Finalist,
        // Sin sueldo final confirmado no hay base para el cargo y `hire()`
        // aborta antes de tocar la etapa.
        'final_salary_amount' => 38000,
        'final_salary_period' => 'mes',
        'final_salary_confirmed_at' => now(),
    ]);

    $otherProfileA = CandidateProfile::factory()->create([
        'state' => CandidateState::Activo->value,
    ]);
    $otherAssignmentA = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $otherProfileA->id,
        'stage' => AssignmentStage::Presented,
    ]);

    $otherProfileB = CandidateProfile::factory()->create([
        'state' => CandidateState::Activo->value,
    ]);
    $otherAssignmentB = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $otherProfileB->id,
        'stage' => AssignmentStage::Interviewing,
    ]);

    // Un candidato ya rechazado previamente no debe tocarse.
    $rejectedProfile = CandidateProfile::factory()->create([
        'state' => CandidateState::Activo->value,
    ]);
    $rejectedAssignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $rejectedProfile->id,
        'stage' => AssignmentStage::Rejected,
        'rejected_at' => now()->subDay(),
        'rejection_reason' => 'Perfil no afín',
    ]);

    return [
        'company' => $company,
        'owner' => $owner,
        'vacancy' => $vacancy,
        'hired' => $hiredAssignment,
        'otherA' => $otherAssignmentA,
        'otherB' => $otherAssignmentB,
        'rejected' => $rejectedAssignment,
    ];
}

it('marks vacancy as cubierta and the assignment as hired', function (): void {
    Notification::fake();
    $ctx = hireScenario();

    app(HireService::class)->hire($ctx['hired'], $ctx['owner']);

    expect($ctx['hired']->fresh()->stage->value)->toBe('hired')
        ->and($ctx['hired']->fresh()->hired_at)->not->toBeNull()
        ->and($ctx['vacancy']->fresh()->state->value)->toBe('cubierta')
        ->and($ctx['vacancy']->fresh()->filled_at)->not->toBeNull();
});

it('auto-withdraws other active assignments with reason', function (): void {
    Notification::fake();
    $ctx = hireScenario();

    app(HireService::class)->hire($ctx['hired'], $ctx['owner']);

    $otherA = $ctx['otherA']->fresh();
    $otherB = $ctx['otherB']->fresh();

    expect($otherA->stage->value)->toBe('withdrawn')
        ->and($otherA->withdrawn_at)->not->toBeNull()
        ->and($otherA->rejection_reason)->toBe('Vacante cubierta por otro candidato')
        ->and($otherB->stage->value)->toBe('withdrawn');
});

it('does not touch previously rejected assignments', function (): void {
    Notification::fake();
    $ctx = hireScenario();

    app(HireService::class)->hire($ctx['hired'], $ctx['owner']);

    $rejected = $ctx['rejected']->fresh();
    expect($rejected->stage->value)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('Perfil no afín');
});

it('notifies hired candidate and auto-withdrawn candidates', function (): void {
    Notification::fake();
    $ctx = hireScenario();

    app(HireService::class)->hire($ctx['hired'], $ctx['owner']);

    Notification::assertSentTo(
        $ctx['hired']->candidateProfile->user,
        CandidateHiredNotification::class,
    );
    Notification::assertSentTo(
        $ctx['otherA']->candidateProfile->user,
        AssignmentRejectedNotification::class,
    );
    Notification::assertSentTo(
        $ctx['otherB']->candidateProfile->user,
        AssignmentRejectedNotification::class,
    );

    // El candidato ya rechazado previamente NO recibe la notificación.
    Notification::assertNotSentTo(
        $ctx['rejected']->candidateProfile->user,
        AssignmentRejectedNotification::class,
    );
});

it('notifies the company owner', function (): void {
    Notification::fake();
    $ctx = hireScenario();

    app(HireService::class)->hire($ctx['hired'], $ctx['owner']);

    Notification::assertSentTo(
        $ctx['owner'],
        CandidateHiredNotification::class,
    );
});

it('rejects hiring an assignment whose stage cannot transition to hired', function (): void {
    Notification::fake();
    $ctx = hireScenario();
    $ctx['hired']->forceFill(['stage' => AssignmentStage::Sourced->value])->save();

    expect(fn () => app(HireService::class)->hire($ctx['hired'], $ctx['owner']))
        ->toThrow(RuntimeException::class);
});
