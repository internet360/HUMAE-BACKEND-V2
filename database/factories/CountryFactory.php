<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->lexify('??')),
            'name' => fake()->country(),
            'phone_code' => '+'.fake()->numberBetween(1, 999),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
