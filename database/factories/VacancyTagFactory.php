<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VacancyTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VacancyTag>
 */
class VacancyTagFactory extends Factory
{
    protected $model = VacancyTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'-'.fake()->word();

        return [
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
