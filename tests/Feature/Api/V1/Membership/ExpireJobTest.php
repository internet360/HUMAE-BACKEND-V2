<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Jobs\ExpireMembershipsJob;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Services\MembershipService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    MembershipPlan::factory()->create([
        'code' => 'candidate_6m',
        'salary_currency_id' => $mxn->id,
        'duration_days' => 180,
        'is_active' => true,
    ]);
});

it('marks expired memberships when running the scheduled job', function (): void {
    $plan = MembershipPlan::where('code', 'candidate_6m')->first();

    $expiredUser = User::factory()->create();
    $activeUser = User::factory()->create();

    $expired = Membership::factory()->create([
        'user_id' => $expiredUser->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDays(200),
        'expires_at' => now()->subDays(1),
    ]);

    $active = Membership::factory()->create([
        'user_id' => $activeUser->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'started_at' => now()->subDays(10),
        'expires_at' => now()->addDays(50),
    ]);

    (new ExpireMembershipsJob)->handle(app(MembershipService::class));

    expect($expired->fresh()->status->value)->toBe('expired');
    expect($active->fresh()->status->value)->toBe('active');
});
