<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCatalog;
use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    use IsCatalog;

    protected $fillable = ['code', 'name', 'phone_code', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<State, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
