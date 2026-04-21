<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PsychometricTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsychometricTest extends Model
{
    /** @use HasFactory<PsychometricTestFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'time_limit_minutes',
        'passing_score',
        'instructions',
        'sort_order',
        'is_active',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'time_limit_minutes' => 'integer',
            'passing_score' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    /** @return HasMany<PsychometricTestSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(PsychometricTestSection::class);
    }

    /** @return HasMany<PsychometricQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(PsychometricQuestion::class);
    }

    /** @return HasMany<PsychometricAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PsychometricAttempt::class);
    }
}
