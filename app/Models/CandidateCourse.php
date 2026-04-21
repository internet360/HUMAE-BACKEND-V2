<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CandidateCourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property string $name
 * @property string|null $institution
 * @property int|null $duration_hours
 * @property Carbon|null $completed_at
 * @property string|null $certificate_url
 * @property int $sort_order
 */
class CandidateCourse extends Model
{
    /** @use HasFactory<CandidateCourseFactory> */
    use HasFactory;

    protected $fillable = [
        'candidate_profile_id',
        'name',
        'institution',
        'duration_hours',
        'completed_at',
        'certificate_url',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'date',
            'duration_hours' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }
}
