<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssignmentStage;
use App\Enums\Priority;
use App\Models\CandidateProfile;
use App\Models\Vacancy;
use App\Models\VacancyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VacancyAssignment>
 */
class VacancyAssignmentFactory extends Factory
{
    protected $model = VacancyAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vacancy_id' => Vacancy::factory(),
            'candidate_profile_id' => CandidateProfile::factory(),
            'stage' => AssignmentStage::Presented,
            'priority' => Priority::Normal,
            'presented_at' => now(),
        ];
    }
}
