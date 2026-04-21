<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CandidateExperience;
use App\Models\CandidateProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates an experience for the authenticated candidate', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me/profile')->assertOk(); // auto-creates profile

    $response = $this->postJson('/api/v1/me/profile/experiences', [
        'company_name' => 'Acme',
        'position_title' => 'Diseñadora UX',
        'start_date' => '2022-01-15',
        'end_date' => '2024-06-30',
        'description' => 'Lideré el rediseño de onboarding.',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.company_name', 'Acme')
        ->assertJsonPath('data.position_title', 'Diseñadora UX');
});

it('lists only the authenticated user experiences', function (): void {
    $userA = User::factory()->create();
    $userA->assignRole(UserRole::Candidate->value);
    $profileA = CandidateProfile::factory()->create(['user_id' => $userA->id]);
    CandidateExperience::factory()->create([
        'candidate_profile_id' => $profileA->id,
        'company_name' => 'MiEmpresa',
    ]);

    $userB = User::factory()->create();
    $userB->assignRole(UserRole::Candidate->value);
    $profileB = CandidateProfile::factory()->create(['user_id' => $userB->id]);
    CandidateExperience::factory()->create([
        'candidate_profile_id' => $profileB->id,
        'company_name' => 'OtraEmpresa',
    ]);

    Sanctum::actingAs($userA);
    $response = $this->getJson('/api/v1/me/profile/experiences');

    $response->assertOk();
    $companies = collect($response->json('data'))->pluck('company_name')->all();

    expect($companies)->toContain('MiEmpresa')
        ->and($companies)->not->toContain('OtraEmpresa');
});

it('forbids updating an experience from another candidate', function (): void {
    $userA = User::factory()->create();
    $userA->assignRole(UserRole::Candidate->value);
    $profileA = CandidateProfile::factory()->create(['user_id' => $userA->id]);
    $experienceA = CandidateExperience::factory()->create([
        'candidate_profile_id' => $profileA->id,
    ]);

    $userB = User::factory()->create();
    $userB->assignRole(UserRole::Candidate->value);
    CandidateProfile::factory()->create(['user_id' => $userB->id]);

    Sanctum::actingAs($userB);
    $response = $this->patchJson("/api/v1/me/profile/experiences/{$experienceA->id}", [
        'position_title' => 'Hacked',
    ]);

    $response->assertStatus(404);
});

it('deletes own experience', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    $profile = CandidateProfile::factory()->create(['user_id' => $user->id]);
    $experience = CandidateExperience::factory()->create([
        'candidate_profile_id' => $profile->id,
    ]);

    Sanctum::actingAs($user);
    $this->deleteJson("/api/v1/me/profile/experiences/{$experience->id}")->assertStatus(204);

    expect(CandidateExperience::find($experience->id))->toBeNull();
});
