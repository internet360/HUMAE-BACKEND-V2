<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CandidateReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateReference extends Model
{
    /** @use HasFactory<CandidateReferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'candidate_profile_id',
        'name',
        'relationship',
        'company',
        'position_title',
        'phone',
        'email',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }
}
