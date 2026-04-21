<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCatalog;
use Database\Factories\VacancyCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VacancyCategory extends Model
{
    /** @use HasFactory<VacancyCategoryFactory> */
    use HasFactory;

    use IsCatalog;

    protected $table = 'vacancy_categories';

    protected $fillable = ['code', 'name', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
