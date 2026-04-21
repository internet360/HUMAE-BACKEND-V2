<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'membership_plan_id' => MembershipPlan::factory(),
            'status' => MembershipStatus::Active,
            'started_at' => now(),
            'expires_at' => now()->addDays(180),
            'auto_renew' => false,
        ];
    }
}
