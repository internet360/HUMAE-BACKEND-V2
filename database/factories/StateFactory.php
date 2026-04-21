<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    protected $model = State::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'code' => Str::upper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->word().' State',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
