<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CandidateCourse;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateCourse>
 */
class CandidateCourseFactory extends Factory
{
    protected $model = CandidateCourse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'name' => fake()->word().'-'.fake()->word(),
            'institution' => fake()->company(),
            'duration_hours' => fake()->numberBetween(8, 120),
            'completed_at' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'sort_order' => 0,
        ];
    }
}
