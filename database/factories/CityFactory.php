<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city();

        return [
            'state_id' => State::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(5),
            'is_active' => true,
        ];
    }
}
