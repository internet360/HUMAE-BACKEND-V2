<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CandidateExperience;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateExperience>
 */
class CandidateExperienceFactory extends Factory
{
    protected $model = CandidateExperience::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-10 years', '-1 year');

        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'company_name' => fake()->company(),
            'position_title' => fake()->jobTitle(),
            'location' => fake()->city(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween($start, 'now')->format('Y-m-d'),
            'is_current' => false,
            'description' => fake()->paragraph(),
            'sort_order' => 0,
        ];
    }
}
