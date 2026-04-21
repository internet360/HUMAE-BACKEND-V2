<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttemptStatus;
use Database\Factories\PsychometricAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property int $psychometric_test_id
 * @property AttemptStatus|null $status
 * @property Carbon|null $started_at
 * @property Carbon|null $submitted_at
 * @property int|null $duration_seconds
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property-read PsychometricTest|null $test
 * @property-read PsychometricResult|null $result
 */
class PsychometricAttempt extends Model
{
    /** @use HasFactory<PsychometricAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'candidate_profile_id',
        'psychometric_test_id',
        'status',
        'started_at',
        'submitted_at',
        'duration_seconds',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return BelongsTo<PsychometricTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(PsychometricTest::class, 'psychometric_test_id');
    }

    /** @return HasMany<PsychometricAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(PsychometricAnswer::class);
    }

    /** @return HasOne<PsychometricResult, $this> */
    public function result(): HasOne
    {
        return $this->hasOne(PsychometricResult::class);
    }
}
