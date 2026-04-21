<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CandidateEducation;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateEducation>
 */
class CandidateEducationFactory extends Factory
{
    protected $model = CandidateEducation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'institution' => fake()->company().' University',
            'field_of_study' => fake()->word().'-'.fake()->word(),
            'location' => fake()->city(),
            'start_date' => fake()->dateTimeBetween('-10 years', '-4 years')->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween('-4 years', '-1 year')->format('Y-m-d'),
            'is_current' => false,
            'status' => 'concluido',
            'sort_order' => 0,
        ];
    }
}
