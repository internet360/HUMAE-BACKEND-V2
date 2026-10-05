<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceRequestStatus;
use Database\Factories\InvoiceRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CFDI request. `rfc` and `legal_name` are personal data: never log them.
 *
 * @property int $id
 * @property int $user_id
 * @property InvoiceRequestStatus $status
 * @property string $rfc
 * @property string $legal_name
 * @property string $tax_regime
 * @property string $postal_code
 * @property string $cfdi_use
 * @property string $email
 * @property string|null $rejection_reason
 * @property Carbon|null $billing_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvoiceRequest extends Model
{
    /** @use HasFactory<InvoiceRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'rfc',
        'legal_name',
        'tax_regime',
        'postal_code',
        'cfdi_use',
        'email',
        'rejection_reason',
        'billing_notified_at',
    ];

    /** @var list<string> */
    protected $hidden = ['rfc'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceRequestStatus::class,
            'billing_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<InvoiceRequestPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoiceRequestPayment::class);
    }
}
