<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PsychometricTestSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsychometricTestSection extends Model
{
    /** @use HasFactory<PsychometricTestSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'psychometric_test_id',
        'code',
        'name',
        'description',
        'time_limit_minutes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'time_limit_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PsychometricTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(PsychometricTest::class, 'psychometric_test_id');
    }

    /** @return HasMany<PsychometricQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(PsychometricQuestion::class);
    }
}
