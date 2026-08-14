<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InterviewRequestCandidateState;
use App\Models\CandidateProfile;
use App\Models\InterviewRequest;
use App\Models\InterviewRequestCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewRequestCandidate>
 */
class InterviewRequestCandidateFactory extends Factory
{
    protected $model = InterviewRequestCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interview_request_id' => InterviewRequest::factory(),
            'candidate_profile_id' => CandidateProfile::factory(),
            'state' => InterviewRequestCandidateState::Pendiente->value,
        ];
    }
}
