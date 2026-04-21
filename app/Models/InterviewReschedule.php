<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InterviewRescheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $interview_id
 * @property int $requested_by
 * @property Carbon|null $previous_scheduled_at
 * @property Carbon|null $new_scheduled_at
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class InterviewReschedule extends Model
{
    /** @use HasFactory<InterviewRescheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'interview_id',
        'requested_by',
        'previous_scheduled_at',
        'new_scheduled_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_scheduled_at' => 'datetime',
            'new_scheduled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
