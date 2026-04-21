<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DirectoryFavoriteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $recruiter_id
 * @property int $candidate_profile_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DirectoryFavorite extends Model
{
    /** @use HasFactory<DirectoryFavoriteFactory> */
    use HasFactory;

    protected $fillable = [
        'recruiter_id',
        'candidate_profile_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recruiter_id');
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }
}
