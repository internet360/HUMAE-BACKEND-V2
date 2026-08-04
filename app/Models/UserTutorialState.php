<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TutorialChannel;
use App\Enums\TutorialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per user per tutorial, forever: it must survive a change of
 * browser or device, which is exactly why it lives here and not in
 * localStorage.
 *
 * @property int $id
 * @property int $user_id
 * @property string $tutorial_key
 * @property TutorialStatus $status
 * @property int $version
 * @property TutorialChannel|null $channel
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class UserTutorialState extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'tutorial_key',
        'status',
        'version',
        'channel',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TutorialStatus::class,
            'channel' => TutorialChannel::class,
            'version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
