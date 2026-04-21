<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    MembershipPlan::factory()->create([
        'code' => 'candidate_6m',
        'price' => 499,
        'duration_days' => 180,
        'salary_currency_id' => $mxn->id,
        'is_active' => true,
    ]);
});

it('returns empty state when user has no memberships', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/me/membership');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.current', null)
        ->assertJsonPath('data.history', [])
        ->assertJsonPath('data.available_plan.code', 'candidate_6m');
});

it('returns current active membership and plan', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::Candidate->value);

    $plan = MembershipPlan::where('code', 'candidate_6m')->first();

    Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDays(10),
        'expires_at' => now()->addDays(170),
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/me/membership');

    $response
        ->assertOk()
        ->assertJsonPath('data.current.status', 'active')
        ->assertJsonPath('data.current.is_active', true)
        ->assertJsonPath('data.current.plan.code', 'candidate_6m');
});

it('rejects unauthenticated access', function (): void {
    $this->getJson('/api/v1/me/membership')->assertStatus(401);
});
