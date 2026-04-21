<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared behavior for simple admin-managed catalogs.
 *
 * Expected schema: id, code (unique), name, sort_order, is_active, timestamps.
 */
trait IsCatalog
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
