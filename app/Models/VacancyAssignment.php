<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssignmentStage;
use App\Enums\Priority;
use Database\Factories\VacancyAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vacancy_id
 * @property int $candidate_profile_id
 * @property int|null $assigned_by
 * @property AssignmentStage|null $stage
 * @property Priority|null $priority
 * @property int|null $score
 * @property string|null $recruiter_notes
 * @property string|null $company_notes
 * @property string|null $rejection_reason
 * @property Carbon|null $presented_at
 * @property Carbon|null $shortlisted_at
 * @property Carbon|null $interviewed_at
 * @property Carbon|null $offer_sent_at
 * @property Carbon|null $hired_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vacancy|null $vacancy
 * @property-read CandidateProfile|null $candidateProfile
 */
class VacancyAssignment extends Model
{
    /** @use HasFactory<VacancyAssignmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'vacancy_id',
        'candidate_profile_id',
        'assigned_by',
        'stage',
        'priority',
        'score',
        'recruiter_notes',
        'company_notes',
        'rejection_reason',
        'presented_at',
        'shortlisted_at',
        'interviewed_at',
        'offer_sent_at',
        'hired_at',
        'rejected_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => AssignmentStage::class,
            'priority' => Priority::class,
            'score' => 'integer',
            'presented_at' => 'datetime',
            'shortlisted_at' => 'datetime',
            'interviewed_at' => 'datetime',
            'offer_sent_at' => 'datetime',
            'hired_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return HasMany<VacancyAssignmentNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(VacancyAssignmentNote::class);
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }
}
