<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionType;
use Database\Factories\PsychometricQuestionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $psychometric_test_id
 * @property int|null $psychometric_test_section_id
 * @property QuestionType|null $type
 * @property string $prompt
 * @property string|null $image_url
 * @property string|null $dimension
 * @property int $weight
 * @property bool $is_reverse_scored
 * @property int $sort_order
 * @property-read Collection<int, PsychometricQuestionOption> $options
 */
class PsychometricQuestion extends Model
{
    /** @use HasFactory<PsychometricQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'psychometric_test_id',
        'psychometric_test_section_id',
        'type',
        'prompt',
        'image_url',
        'dimension',
        'weight',
        'is_reverse_scored',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'weight' => 'integer',
            'is_reverse_scored' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PsychometricTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(PsychometricTest::class, 'psychometric_test_id');
    }

    /** @return BelongsTo<PsychometricTestSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(PsychometricTestSection::class, 'psychometric_test_section_id');
    }

    /** @return HasMany<PsychometricQuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PsychometricQuestionOption::class);
    }
}
