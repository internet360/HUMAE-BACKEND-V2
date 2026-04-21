<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\PsychometricQuestion;
use App\Models\PsychometricTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PsychometricQuestion>
 */
class PsychometricQuestionFactory extends Factory
{
    protected $model = PsychometricQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'psychometric_test_id' => PsychometricTest::factory(),
            'type' => QuestionType::MultipleChoice,
            'prompt' => fake()->sentence().'?',
            'weight' => 1,
            'is_reverse_scored' => false,
            'sort_order' => 0,
        ];
    }
}
