<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CandidateCertificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property string $name
 * @property string $issuer
 * @property string|null $credential_id
 * @property string|null $credential_url
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property int $sort_order
 */
class CandidateCertification extends Model
{
    /** @use HasFactory<CandidateCertificationFactory> */
    use HasFactory;

    protected $fillable = [
        'candidate_profile_id',
        'name',
        'issuer',
        'credential_id',
        'credential_url',
        'issued_at',
        'expires_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }
}
