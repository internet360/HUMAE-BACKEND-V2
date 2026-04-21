<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PsychometricAnswer;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PsychometricAnswer>
 */
class PsychometricAnswerFactory extends Factory
{
    protected $model = PsychometricAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'psychometric_attempt_id' => PsychometricAttempt::factory(),
            'psychometric_question_id' => PsychometricQuestion::factory(),
            'value' => fake()->word(),
            'score' => fake()->numberBetween(0, 10),
            'time_spent_seconds' => fake()->numberBetween(5, 120),
        ];
    }
}
