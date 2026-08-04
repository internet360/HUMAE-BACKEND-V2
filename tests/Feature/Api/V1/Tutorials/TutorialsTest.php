<?php

declare(strict_types=1);

use App\Enums\TutorialChannel;
use App\Enums\TutorialStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserTutorialState;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function tutorialActor(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

it('lists only the tutorial applicable to the candidate role, unresolved by default', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Candidate));

    $response = $this->getJson('/api/v1/me/tutorials');

    $response->assertOk()->assertJsonPath('success', true);

    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['tutorial_key'])->toBe('candidate_home')
        ->and($data[0]['version'])->toBe(1)
        ->and($data[0]['should_show'])->toBeTrue()
        ->and($data[0]['status'])->toBeNull()
        ->and($data[0]['channel'])->toBeNull()
        ->and($data[0]['video_url'])->toBeNull();
});

it('resolves the recruiter and company home tutorials for their own roles', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Recruiter));
    $recruiterData = $this->getJson('/api/v1/me/tutorials')->json('data');
    expect($recruiterData)->toHaveCount(1)
        ->and($recruiterData[0]['tutorial_key'])->toBe('recruiter_home');

    Sanctum::actingAs(tutorialActor(UserRole::CompanyUser));
    $companyData = $this->getJson('/api/v1/me/tutorials')->json('data');
    expect($companyData)->toHaveCount(1)
        ->and($companyData[0]['tutorial_key'])->toBe('company_home');
});

it('returns an empty list for a role with no home tutorial', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Admin));

    $this->getJson('/api/v1/me/tutorials')->assertOk()->assertJsonPath('data', []);
});

it('completes a tutorial via the chosen channel and flips should_show to false', function (): void {
    $candidate = tutorialActor(UserRole::Candidate);
    Sanctum::actingAs($candidate);

    $response = $this->postJson('/api/v1/me/tutorials/candidate_home/complete', ['channel' => 'tour']);

    $response->assertOk()
        ->assertJsonPath('data.status', TutorialStatus::Completed->value)
        ->assertJsonPath('data.channel', TutorialChannel::Tour->value)
        ->assertJsonPath('data.should_show', false);

    $state = UserTutorialState::where('user_id', $candidate->id)->where('tutorial_key', 'candidate_home')->first();
    expect($state)->not->toBeNull();
    expect($state->status)->toBe(TutorialStatus::Completed);

    $list = $this->getJson('/api/v1/me/tutorials')->json('data');
    expect($list[0]['should_show'])->toBeFalse();
});

it('is idempotent completing the same tutorial twice', function (): void {
    $candidate = tutorialActor(UserRole::Candidate);
    Sanctum::actingAs($candidate);

    $this->postJson('/api/v1/me/tutorials/candidate_home/complete', ['channel' => 'tour'])->assertOk();
    $this->postJson('/api/v1/me/tutorials/candidate_home/complete', ['channel' => 'video'])->assertOk();

    expect(
        UserTutorialState::where('user_id', $candidate->id)->where('tutorial_key', 'candidate_home')->count()
    )->toBe(1);
});

it('skips a tutorial without a channel and flips should_show to false', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Candidate));

    $response = $this->postJson('/api/v1/me/tutorials/candidate_home/skip');

    $response->assertOk()
        ->assertJsonPath('data.status', TutorialStatus::Skipped->value)
        ->assertJsonPath('data.channel', null)
        ->assertJsonPath('data.should_show', false);
});

it('is idempotent skipping the same tutorial twice', function (): void {
    $candidate = tutorialActor(UserRole::Candidate);
    Sanctum::actingAs($candidate);

    $this->postJson('/api/v1/me/tutorials/candidate_home/skip')->assertOk();
    $this->postJson('/api/v1/me/tutorials/candidate_home/skip')->assertOk();

    expect(
        UserTutorialState::where('user_id', $candidate->id)->where('tutorial_key', 'candidate_home')->count()
    )->toBe(1);
});

it('shows the tutorial again after the configured version is bumped', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Candidate));

    $this->postJson('/api/v1/me/tutorials/candidate_home/complete', ['channel' => 'tour'])->assertOk();

    config(['tutorials.candidate_home.version' => 2]);

    $data = $this->getJson('/api/v1/me/tutorials')->json('data');
    expect($data[0]['should_show'])->toBeTrue()
        ->and($data[0]['version'])->toBe(2);
});

it('returns 404 for an unknown tutorial key', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Candidate));

    $this->postJson('/api/v1/me/tutorials/not-a-real-key/complete', ['channel' => 'tour'])
        ->assertStatus(404);
    $this->postJson('/api/v1/me/tutorials/not-a-real-key/skip')
        ->assertStatus(404);
});

it('returns 404 when the key does not apply to the caller role', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Candidate));

    $this->postJson('/api/v1/me/tutorials/recruiter_home/complete', ['channel' => 'tour'])
        ->assertStatus(404);
});

it('rejects an invalid channel on complete', function (): void {
    Sanctum::actingAs(tutorialActor(UserRole::Candidate));

    $this->postJson('/api/v1/me/tutorials/candidate_home/complete', ['channel' => 'carrier-pigeon'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['channel']);
});

it('rejects unauthenticated access to every tutorial endpoint', function (): void {
    $this->getJson('/api/v1/me/tutorials')->assertStatus(401);
    $this->postJson('/api/v1/me/tutorials/candidate_home/complete', ['channel' => 'tour'])->assertStatus(401);
    $this->postJson('/api/v1/me/tutorials/candidate_home/skip')->assertStatus(401);
});
