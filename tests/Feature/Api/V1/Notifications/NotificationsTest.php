<?php

declare(strict_types=1);

use App\Enums\AssignmentStage;
use App\Enums\CandidateState;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Enums\VacancyState;
use App\Models\CandidateProfile;
use App\Models\Company;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use App\Notifications\AssignmentStageChangedNotification;
use App\Notifications\InterviewScheduledNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function notifCandidate(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    return $user;
}

function notifRecruiter(): User
{
    $user = User::factory()->create();
    $user->assignRole(UserRole::Recruiter->value);

    return $user;
}

it('lists user notifications with unread_count', function (): void {
    $user = notifCandidate();
    Sanctum::actingAs($user);

    $user->notify(new AssignmentStageChangedNotification(
        VacancyAssignment::factory()->create(),
        AssignmentStage::Presented,
    ));

    $response = $this->getJson('/api/v1/me/notifications');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('meta.unread_count', 1);

    expect(count($response->json('data')))->toBe(1);
});

it('marks a notification as read', function (): void {
    $user = notifCandidate();
    Sanctum::actingAs($user);

    $user->notify(new AssignmentStageChangedNotification(
        VacancyAssignment::factory()->create(),
        AssignmentStage::Presented,
    ));

    $notificationId = $user->notifications()->first()->id;

    $response = $this->postJson("/api/v1/me/notifications/{$notificationId}/read");

    $response->assertOk()
        ->assertJsonPath('data.read_at', fn ($v) => $v !== null);

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('marks all notifications as read', function (): void {
    $user = notifCandidate();
    Sanctum::actingAs($user);

    $assignment = VacancyAssignment::factory()->create();
    $user->notify(new AssignmentStageChangedNotification($assignment, AssignmentStage::Presented));
    $user->notify(new AssignmentStageChangedNotification($assignment, AssignmentStage::Interviewing));

    expect($user->unreadNotifications()->count())->toBe(2);

    $response = $this->postJson('/api/v1/me/notifications/read-all');

    $response->assertOk()->assertJsonPath('data.unread_count', 0);
    expect($user->unreadNotifications()->count())->toBe(0);
});

it('cannot mark someone else notification as read', function (): void {
    $userA = notifCandidate();
    $userA->notify(new AssignmentStageChangedNotification(
        VacancyAssignment::factory()->create(),
        AssignmentStage::Presented,
    ));
    $notificationId = $userA->notifications()->first()->id;

    $userB = notifCandidate();
    Sanctum::actingAs($userB);

    $this->postJson("/api/v1/me/notifications/{$notificationId}/read")->assertStatus(404);
});

it('dispatches notification when interview is scheduled', function (): void {
    Notification::fake();

    $candidateUser = User::factory()->create();
    $candidateUser->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create([
        'user_id' => $candidateUser->id,
        'state' => CandidateState::Activo->value,
    ]);

    // Activa membresía para validación de pipeline
    $currency = SalaryCurrency::where('code', 'MXN')->first()
        ?? SalaryCurrency::factory()->create(['code' => 'MXN']);
    $plan = MembershipPlan::where('code', 'candidate_6m')->first()
        ?? MembershipPlan::factory()->create([
            'code' => 'candidate_6m',
            'salary_currency_id' => $currency->id,
        ]);
    Membership::factory()->create([
        'user_id' => $candidateUser->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addDays(100),
    ]);

    $company = Company::factory()->create();
    $vacancy = Vacancy::factory()->create([
        'company_id' => $company->id,
        'state' => VacancyState::ConCandidatosAsignados,
    ]);
    $assignment = VacancyAssignment::factory()->create([
        'vacancy_id' => $vacancy->id,
        'candidate_profile_id' => $profile->id,
    ]);

    $recruiter = notifRecruiter();
    Sanctum::actingAs($recruiter);

    $this->postJson('/api/v1/interviews', [
        'vacancy_assignment_id' => $assignment->id,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
    ])->assertCreated();

    Notification::assertSentTo($candidateUser, InterviewScheduledNotification::class);
});

it('rejects unauthenticated notifications access', function (): void {
    $this->getJson('/api/v1/me/notifications')->assertStatus(401);
});
