<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InterviewMode;
use App\Enums\InterviewState;
use App\Models\Interview;
use App\Models\VacancyAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interview>
 */
class InterviewFactory extends Factory
{
    protected $model = Interview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vacancy_assignment_id' => VacancyAssignment::factory(),
            'round' => 1,
            'state' => InterviewState::Propuesta,
            'mode' => InterviewMode::Online,
            'scheduled_at' => now()->addDays(3),
            'duration_minutes' => 60,
            'timezone' => 'America/Mexico_City',
        ];
    }
}
