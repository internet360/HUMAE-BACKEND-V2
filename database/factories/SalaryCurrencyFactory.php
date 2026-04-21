<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SalaryCurrency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalaryCurrency>
 */
class SalaryCurrencyFactory extends Factory
{
    protected $model = SalaryCurrency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->lexify('???')),
            'name' => fake()->currencyCode(),
            'symbol' => fake()->randomElement(['$', '€', '£', '¥', 'MX$']),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
