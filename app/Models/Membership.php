<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $membership_plan_id
 * @property MembershipStatus|null $status
 * @property Carbon|null $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property bool $auto_renew
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'membership_plan_id',
        'status',
        'started_at',
        'expires_at',
        'cancelled_at',
        'cancel_reason',
        'auto_renew',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Active)
            ->where('expires_at', '>', now());
    }
}
