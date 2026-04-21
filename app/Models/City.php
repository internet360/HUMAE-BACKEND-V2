<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCatalog;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    use IsCatalog;

    protected $fillable = ['state_id', 'name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<State, covariant self> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
