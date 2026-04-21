<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterviewMode;
use App\Enums\InterviewState;
use Database\Factories\InterviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vacancy_assignment_id
 * @property int|null $scheduled_by
 * @property int $round
 * @property string|null $title
 * @property InterviewState|null $state
 * @property InterviewMode|null $mode
 * @property Carbon|null $scheduled_at
 * @property int $duration_minutes
 * @property string $timezone
 * @property string|null $meeting_url
 * @property string|null $meeting_provider
 * @property string|null $meeting_id
 * @property string|null $location
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $rating
 * @property string|null $recruiter_feedback
 * @property string|null $company_feedback
 * @property string|null $recommendation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read VacancyAssignment|null $assignment
 */
class Interview extends Model
{
    /** @use HasFactory<InterviewFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'vacancy_assignment_id',
        'scheduled_by',
        'round',
        'title',
        'state',
        'mode',
        'scheduled_at',
        'duration_minutes',
        'timezone',
        'meeting_url',
        'meeting_provider',
        'meeting_id',
        'location',
        'started_at',
        'ended_at',
        'rating',
        'recruiter_feedback',
        'company_feedback',
        'recommendation',
    ];

    protected function casts(): array
    {
        return [
            'state' => InterviewState::class,
            'mode' => InterviewMode::class,
            'round' => 'integer',
            'duration_minutes' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'rating' => 'integer',
        ];
    }

    /** @return BelongsTo<VacancyAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VacancyAssignment::class, 'vacancy_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /** @return HasMany<InterviewReschedule, $this> */
    public function reschedules(): HasMany
    {
        return $this->hasMany(InterviewReschedule::class);
    }
}
