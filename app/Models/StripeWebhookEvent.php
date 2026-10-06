<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dedup record for a Stripe webhook event. A row exists only if the event was
 * handled: it is inserted in the same transaction as the handler's effects.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property bool $livemode
 * @property Carbon $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'type',
        'livemode',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
