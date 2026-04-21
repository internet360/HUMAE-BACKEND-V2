<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->languageCode(),
            'name' => fake()->unique()->word(),
            'native_name' => fake()->word(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
