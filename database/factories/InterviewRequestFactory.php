<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InterviewRequestState;
use App\Models\Company;
use App\Models\InterviewRequest;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewRequest>
 */
class InterviewRequestFactory extends Factory
{
    protected $model = InterviewRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'vacancy_id' => Vacancy::factory(),
            'requested_by_user_id' => User::factory(),
            'state' => InterviewRequestState::Pendiente->value,
            'proposed_slot_1_at' => now()->addDays(3)->setTime(10, 0),
            'proposed_slot_2_at' => now()->addDays(4)->setTime(16, 0),
            'timezone' => 'America/Mexico_City',
            'note' => null,
            'submitted_at' => now(),
        ];
    }
}
