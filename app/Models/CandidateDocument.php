<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentType;
use Database\Factories\CandidateDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $candidate_profile_id
 * @property DocumentType|null $type
 * @property string $title
 * @property string $file_url
 * @property string $file_provider
 * @property string|null $file_public_id
 * @property string|null $mime_type
 * @property int|null $file_size_bytes
 * @property bool $is_internal
 * @property Carbon|null $uploaded_at
 */
class CandidateDocument extends Model
{
    /** @use HasFactory<CandidateDocumentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'candidate_profile_id',
        'type',
        'title',
        'file_url',
        'file_provider',
        'file_public_id',
        'mime_type',
        'file_size_bytes',
        'is_internal',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'is_internal' => 'boolean',
            'file_size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CandidateProfile, $this> */
    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(CandidateProfile::class);
    }
}
