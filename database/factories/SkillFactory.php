<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    protected $model = Skill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'-'.fake()->word();

        return [
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'category' => fake()->randomElement(['tecnica', 'blanda', 'herramienta', 'metodologia']),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
