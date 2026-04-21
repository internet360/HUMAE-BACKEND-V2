<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Models\CandidateProfile;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Services\ReportsService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->service = new ReportsService;
});

it('returns empty aggregates when there are no candidates', function (): void {
    $result = $this->service->candidatesRegistered(
        Carbon::now()->subDays(30),
        Carbon::now(),
    );

    expect($result['total'])->toBe(0);
    expect($result['by_day'])->toBe([]);
    expect($result['by_state'])->toBe([]);
});

it('counts registered candidates in the date range', function (): void {
    CandidateProfile::factory()->count(3)->create([
        'created_at' => now()->subDays(5),
    ]);
    CandidateProfile::factory()->create([
        'created_at' => now()->subDays(60), // fuera del rango
    ]);

    $result = $this->service->candidatesRegistered(
        Carbon::now()->subDays(30),
        Carbon::now(),
    );

    expect($result['total'])->toBe(3);
});

it('reports zero active memberships when there are none', function (): void {
    $result = $this->service->activeMemberships();

    expect($result['active'])->toBe(0);
    expect($result['by_plan'])->toBe([]);
});

it('counts active memberships and groups them by plan', function (): void {
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    $plan = MembershipPlan::factory()->create([
        'salary_currency_id' => $mxn->id,
        'code' => 'candidate_6m',
        'duration_days' => 180,
    ]);

    Membership::factory()->count(2)->create([
        'user_id' => fn () => User::factory()->create()->id,
        'membership_plan_id' => $plan->id,
        'status' => MembershipStatus::Active,
        'expires_at' => now()->addDays(50),
    ]);

    $result = $this->service->activeMemberships();

    expect($result['active'])->toBe(2);
});
