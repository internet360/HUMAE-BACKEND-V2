<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PsychometricAttempt;
use App\Models\PsychometricResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PsychometricResult>
 */
class PsychometricResultFactory extends Factory
{
    protected $model = PsychometricResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'psychometric_attempt_id' => PsychometricAttempt::factory(),
            'total_score' => fake()->randomFloat(2, 0, 100),
            'percentile' => fake()->randomFloat(2, 0, 100),
            'grade' => fake()->randomElement(['A', 'B', 'C']),
            'passed' => fake()->boolean(),
        ];
    }
}
