<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CandidateCertification;
use App\Models\CandidateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateCertification>
 */
class CandidateCertificationFactory extends Factory
{
    protected $model = CandidateCertification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'name' => fake()->word().'-'.fake()->word(),
            'issuer' => fake()->company(),
            'issued_at' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'sort_order' => 0,
        ];
    }
}
