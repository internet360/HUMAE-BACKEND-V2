<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCatalog;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $functional_area_id
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    use IsCatalog;

    protected $fillable = ['code', 'name', 'functional_area_id', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'functional_area_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<FunctionalArea, $this> */
    public function functionalArea(): BelongsTo
    {
        return $this->belongsTo(FunctionalArea::class);
    }
}
