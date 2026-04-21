<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MembershipPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    /** @use HasFactory<MembershipPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'salary_currency_id',
        'price',
        'duration_days',
        'stripe_price_id',
        'stripe_product_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<SalaryCurrency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(SalaryCurrency::class, 'salary_currency_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
