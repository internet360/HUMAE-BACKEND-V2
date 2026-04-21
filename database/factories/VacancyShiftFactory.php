<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VacancyShift;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VacancyShift>
 */
class VacancyShiftFactory extends Factory
{
    protected $model = VacancyShift::class;

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
