<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CandidateState;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateProfile>
 */
class CandidateProfileFactory extends Factory
{
    protected $model = CandidateProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'headline' => fake()->jobTitle(),
            'summary' => fake()->paragraph(),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-22 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'years_of_experience' => fake()->numberBetween(0, 20),
            'expected_salary_period' => 'mes',
            'availability' => 'inmediata',
            'open_to_relocation' => fake()->boolean(),
            'open_to_remote' => fake()->boolean(),
            'state' => CandidateState::RegistroIncompleto,
        ];
    }
}
