<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttemptStatus;
use App\Models\CandidateProfile;
use App\Models\PsychometricAttempt;
use App\Models\PsychometricTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PsychometricAttempt>
 */
class PsychometricAttemptFactory extends Factory
{
    protected $model = PsychometricAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_profile_id' => CandidateProfile::factory(),
            'psychometric_test_id' => PsychometricTest::factory(),
            'status' => AttemptStatus::InProgress,
            'started_at' => now(),
        ];
    }
}
