<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterviewRequestState;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Contracts\CompanyOwned;
use Database\Factories\InterviewRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $vacancy_id
 * @property int $requested_by_user_id
 * @property InterviewRequestState|null $state
 * @property Carbon|null $proposed_slot_1_at
 * @property Carbon|null $proposed_slot_2_at
 * @property string $timezone
 * @property string|null $note
 * @property int|null $assigned_recruiter_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InterviewRequest extends Model implements CompanyOwned
{
    use BelongsToCompany;

    /** @use HasFactory<InterviewRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'vacancy_id',
        'requested_by_user_id',
        'state',
        'proposed_slot_1_at',
        'proposed_slot_2_at',
        'timezone',
        'note',
        'assigned_recruiter_id',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => InterviewRequestState::class,
            'proposed_slot_1_at' => 'datetime',
            'proposed_slot_2_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Vacancy, $this> */
    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedRecruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_recruiter_id');
    }

    /** @return HasMany<InterviewRequestCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(InterviewRequestCandidate::class);
    }

    /**
     * Los dos horarios propuestos, en orden.
     *
     * @return list<Carbon>
     */
    public function proposedSlots(): array
    {
        return array_values(array_filter([
            $this->proposed_slot_1_at,
            $this->proposed_slot_2_at,
        ]));
    }
}
