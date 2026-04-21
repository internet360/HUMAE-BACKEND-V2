<?php

declare(strict_types=1);

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use App\Models\User;
use App\Services\MembershipService;

beforeEach(function (): void {
    $mxn = SalaryCurrency::factory()->create(['code' => 'MXN']);
    $this->plan = MembershipPlan::factory()->create([
        'salary_currency_id' => $mxn->id,
        'duration_days' => 180,
    ]);
    $this->service = app(MembershipService::class);
});

it('cancel() marks the membership as cancelled with reason and timestamp', function (): void {
    $user = User::factory()->create();
    $membership = Membership::factory()->create([
        'user_id' => $user->id,
        'membership_plan_id' => $this->plan->id,
        'status' => MembershipStatus::Active,
    ]);

    $result = $this->service->cancel($membership, 'duplicate_purchase');

    expect($result->status->value)->toBe('cancelled');
    expect($result->cancel_reason)->toBe('duplicate_purchase');
    expect($result->cancelled_at)->not->toBeNull();
});

it('cancel() works without reason', function (): void {
    $membership = Membership::factory()->create([
        'user_id' => User::factory()->create()->id,
        'membership_plan_id' => $this->plan->id,
        'status' => MembershipStatus::Active,
    ]);

    $result = $this->service->cancel($membership);

    expect($result->status->value)->toBe('cancelled');
    expect($result->cancel_reason)->toBeNull();
});

it('expireStale() returns 0 when there are no expired memberships', function (): void {
    Membership::factory()->create([
        'user_id' => User::factory()->create()->id,
        'membership_plan_id' => $this->plan->id,
        'status' => MembershipStatus::Active,
        'expires_at' => now()->addDays(10),
    ]);

    expect($this->service->expireStale())->toBe(0);
});

it('expireStale() ignores already-expired rows', function (): void {
    Membership::factory()->create([
        'user_id' => User::factory()->create()->id,
        'membership_plan_id' => $this->plan->id,
        'status' => MembershipStatus::Expired,
        'expires_at' => now()->subDays(1),
    ]);

    expect($this->service->expireStale())->toBe(0);
});

it('expireStale() expires multiple stale memberships in one call', function (): void {
    foreach (range(1, 3) as $i) {
        Membership::factory()->create([
            'user_id' => User::factory()->create()->id,
            'membership_plan_id' => $this->plan->id,
            'status' => MembershipStatus::Active,
            'expires_at' => now()->subDays($i),
        ]);
    }

    expect($this->service->expireStale())->toBe(3);
    expect(Membership::where('status', MembershipStatus::Expired->value)->count())->toBe(3);
});
