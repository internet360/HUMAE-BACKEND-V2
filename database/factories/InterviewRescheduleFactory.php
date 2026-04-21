<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Interview;
use App\Models\InterviewReschedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewReschedule>
 */
class InterviewRescheduleFactory extends Factory
{
    protected $model = InterviewReschedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interview_id' => Interview::factory(),
            'requested_by' => User::factory(),
            'previous_scheduled_at' => now(),
            'new_scheduled_at' => now()->addDays(2),
            'reason' => fake()->sentence(),
        ];
    }
}
