<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CandidateEducationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property int|null $degree_level_id
 * @property string $institution
 * @property string|null $field_of_study
 * @property string|null $location
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property bool $is_current
 * @property string|null $status
 * @property string|null $credential_number
 * @property int $sort_order
 */
class CandidateEducation extends Model
{
    /** @use HasFactory<CandidateEducationFactory> */
    use HasFactory;

    protected $table = 'candidate_educations';

    protected $fillable = [
        'candidate_profile_id',
        'degree_level_id',
        'institution',
        'field_of_study',
        'location',
        'start_date',
        'end_date',
        'is_current',
        'status',
        'credential_number',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }

    /** @return BelongsTo<DegreeLevel, $this> */
    public function degreeLevel(): BelongsTo
    {
        return $this->belongsTo(DegreeLevel::class);
    }
}
