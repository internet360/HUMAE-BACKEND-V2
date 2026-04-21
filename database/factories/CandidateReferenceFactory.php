<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CandidateProfile;
use App\Models\CandidateReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateReference>
 */
class CandidateReferenceFactory extends Factory
{
    protected $model = CandidateReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Ex jefe', 'Ex colega', 'Mentor']),
            'company' => fake()->company(),
            'position_title' => fake()->jobTitle(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'sort_order' => 0,
        ];
    }
}
