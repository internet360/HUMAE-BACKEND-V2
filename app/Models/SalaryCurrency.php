<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCatalog;
use Database\Factories\SalaryCurrencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryCurrency extends Model
{
    /** @use HasFactory<SalaryCurrencyFactory> */
    use HasFactory;

    use IsCatalog;

    protected $fillable = ['code', 'name', 'symbol', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
