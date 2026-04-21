<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PsychometricTest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PsychometricTest>
 */
class PsychometricTestFactory extends Factory
{
    protected $model = PsychometricTest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'-'.fake()->word();

        return [
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(['personalidad', 'aptitud', 'valores']),
            'time_limit_minutes' => 30,
            'is_active' => true,
            'is_required' => false,
            'sort_order' => 0,
        ];
    }
}
