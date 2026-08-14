<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterviewRequestCandidateState;
use Database\Factories\InterviewRequestCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $interview_request_id
 * @property int $candidate_profile_id
 * @property InterviewRequestCandidateState|null $state
 * @property string|null $rejection_reason
 * @property int|null $vacancy_assignment_id
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InterviewRequestCandidate extends Model
{
    /** @use HasFactory<InterviewRequestCandidateFactory> */
    use HasFactory;

    protected $fillable = [
        'interview_request_id',
        'candidate_profile_id',
        'state',
        'rejection_reason',
        'vacancy_assignment_id',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => InterviewRequestCandidateState::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InterviewRequest, $this> */
    public function interviewRequest(): BelongsTo
    {
        return $this->belongsTo(InterviewRequest::class);
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return BelongsTo<VacancyAssignment, $this> */
    public function vacancyAssignment(): BelongsTo
    {
        return $this->belongsTo(VacancyAssignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
