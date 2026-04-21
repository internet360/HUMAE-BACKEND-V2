<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PsychometricQuestion;
use App\Models\PsychometricQuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PsychometricQuestionOption>
 */
class PsychometricQuestionOptionFactory extends Factory
{
    protected $model = PsychometricQuestionOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'psychometric_question_id' => PsychometricQuestion::factory(),
            'label' => fake()->sentence(3),
            'value' => (string) fake()->unique()->numberBetween(1, 1000),
            'score' => fake()->numberBetween(0, 10),
            'is_correct' => false,
            'sort_order' => 0,
        ];
    }
}
