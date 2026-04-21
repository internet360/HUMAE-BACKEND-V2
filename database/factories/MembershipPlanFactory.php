<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MembershipPlan;
use App\Models\SalaryCurrency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MembershipPlan>
 */
class MembershipPlanFactory extends Factory
{
    protected $model = MembershipPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'-'.fake()->word();

        return [
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'salary_currency_id' => SalaryCurrency::factory(),
            'price' => fake()->randomFloat(2, 100, 2000),
            'duration_days' => fake()->randomElement([30, 90, 180, 365]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
