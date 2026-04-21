<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PsychometricAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PsychometricAnswer extends Model
{
    /** @use HasFactory<PsychometricAnswerFactory> */
    use HasFactory;

    protected $fillable = [
        'psychometric_attempt_id',
        'psychometric_question_id',
        'psychometric_question_option_id',
        'value',
        'score',
        'time_spent_seconds',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'time_spent_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<PsychometricAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PsychometricAttempt::class, 'psychometric_attempt_id');
    }

    /** @return BelongsTo<PsychometricQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(PsychometricQuestion::class, 'psychometric_question_id');
    }

    /** @return BelongsTo<PsychometricQuestionOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PsychometricQuestionOption::class, 'psychometric_question_option_id');
    }
}
