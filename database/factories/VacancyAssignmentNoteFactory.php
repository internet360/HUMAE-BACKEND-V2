<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\VacancyAssignment;
use App\Models\VacancyAssignmentNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VacancyAssignmentNote>
 */
class VacancyAssignmentNoteFactory extends Factory
{
    protected $model = VacancyAssignmentNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vacancy_assignment_id' => VacancyAssignment::factory(),
            'author_id' => User::factory(),
            'visibility' => 'internal',
            'body' => fake()->paragraph(),
        ];
    }
}
