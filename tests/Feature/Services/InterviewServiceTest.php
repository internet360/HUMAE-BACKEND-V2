<?php

declare(strict_types=1);

use App\Enums\CandidateState;
use App\Enums\InterviewState;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\InterviewReschedule;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Services\InterviewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->service = app(InterviewService::class);
});

function serviceInterviewSetup(VacancyState $state = VacancyState::ConCandidatosAsignados): VacancyAssignment
{
    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);
    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => $state,
    ]);

    return VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
    ]);
}

it('schedule() creates an Interview in propuesta state with round=1', function (): void {
    $assignment = serviceInterviewSetup();
    $actor = User::factory()->create();

    $interview = $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'duration_minutes' => 45,
    ]);

    expect($interview->state)->toBe(InterviewState::Propuesta);
    expect($interview->round)->toBe(1);
    expect($interview->duration_minutes)->toBe(45);
});

it('schedule() transitions the vacancy from con_candidatos_asignados → entrevistas_en_curso', function (): void {
    $assignment = serviceInterviewSetup(VacancyState::ConCandidatosAsignados);
    $actor = User::factory()->create();

    $interview = $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ]);

    expect($interview->assignment->vacancy->fresh()->state)->toBe(VacancyState::EntrevistasEnCurso);
});

it('schedule() fails when vacancy is not in a state that admits interviews', function (): void {
    $assignment = serviceInterviewSetup(VacancyState::Borrador);
    $actor = User::factory()->create();

    $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(1)->toIso8601String(),
    ]);
})->throws(RuntimeException::class, 'La vacante no está en un estado que admita entrevistas.');

it('reschedule() creates an InterviewReschedule row and resets to propuesta', function (): void {
    $assignment = serviceInterviewSetup();
    $actor = User::factory()->create();

    $interview = $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ]);
    $this->service->confirm($interview);

    $newDate = Carbon::now()->addDays(7);
    $rescheduled = $this->service->reschedule($interview->fresh(), $actor, $newDate, 'Conflicto de agenda');

    expect($rescheduled->state)->toBe(InterviewState::Propuesta);
    expect(InterviewReschedule::where('interview_id', $interview->id)->count())->toBe(1);
});

it('confirm() is idempotent', function (): void {
    $assignment = serviceInterviewSetup();
    $actor = User::factory()->create();

    $interview = $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ]);

    $first = $this->service->confirm($interview);
    $second = $this->service->confirm($first->fresh());

    expect($second->state)->toBe(InterviewState::Confirmada);
});

it('cancel() transitions to cancelada and appends reason to recruiter_feedback', function (): void {
    $assignment = serviceInterviewSetup();
    $actor = User::factory()->create();

    $interview = $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ]);

    $cancelled = $this->service->cancel($interview->fresh(), 'Candidato no disponible');

    expect($cancelled->state)->toBe(InterviewState::Cancelada);
    expect($cancelled->recruiter_feedback)->toContain('Candidato no disponible');
});

it('cancel() fails when interview is already realizada (terminal)', function (): void {
    $assignment = serviceInterviewSetup();
    $actor = User::factory()->create();

    $interview = $this->service->schedule($assignment, $actor, [
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ]);
    $interview->forceFill(['state' => InterviewState::Realizada->value])->save();

    $this->service->cancel($interview->fresh());
})->throws(RuntimeException::class, 'La entrevista ya no puede cancelarse.');
