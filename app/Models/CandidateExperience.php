<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CandidateExperienceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property string $company_name
 * @property string $position_title
 * @property int|null $functional_area_id
 * @property string|null $location
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property bool $is_current
 * @property string|null $description
 * @property string|null $achievements
 * @property int $sort_order
 */
class CandidateExperience extends Model
{
    /** @use HasFactory<CandidateExperienceFactory> */
    use HasFactory;

    protected $fillable = [
        'candidate_profile_id',
        'company_name',
        'position_title',
        'functional_area_id',
        'location',
        'start_date',
        'end_date',
        'is_current',
        'description',
        'achievements',
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

    /** @return BelongsTo<FunctionalArea, $this> */
    public function functionalArea(): BelongsTo
    {
        return $this->belongsTo(FunctionalArea::class);
    }
}
