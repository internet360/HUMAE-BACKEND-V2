<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PsychometricQuestionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $psychometric_question_id
 * @property string $label
 * @property string $value
 * @property int $score
 * @property bool $is_correct
 * @property int $sort_order
 */
class PsychometricQuestionOption extends Model
{
    /** @use HasFactory<PsychometricQuestionOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'psychometric_question_id',
        'label',
        'value',
        'score',
        'is_correct',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_correct' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PsychometricQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(PsychometricQuestion::class, 'psychometric_question_id');
    }
}
