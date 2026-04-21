<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PsychometricResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $psychometric_attempt_id
 * @property string|null $total_score
 * @property string|null $percentile
 * @property string|null $grade
 * @property bool $passed
 * @property array<string, float>|null $dimension_scores
 * @property string|null $summary
 * @property string|null $recommendations
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class PsychometricResult extends Model
{
    /** @use HasFactory<PsychometricResultFactory> */
    use HasFactory;

    protected $fillable = [
        'psychometric_attempt_id',
        'total_score',
        'percentile',
        'grade',
        'passed',
        'dimension_scores',
        'summary',
        'recommendations',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_score' => 'decimal:2',
            'percentile' => 'decimal:2',
            'passed' => 'boolean',
            'dimension_scores' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PsychometricAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PsychometricAttempt::class, 'psychometric_attempt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
