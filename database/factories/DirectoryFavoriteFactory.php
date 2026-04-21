<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CandidateProfile;
use App\Models\DirectoryFavorite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryFavorite>
 */
class DirectoryFavoriteFactory extends Factory
{
    protected $model = DirectoryFavorite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruiter_id' => User::factory(),
            'candidate_profile_id' => CandidateProfile::factory(),
        ];
    }
}
