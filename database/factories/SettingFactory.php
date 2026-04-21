<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::slug(fake()->unique()->word().'-'.fake()->word(), '.'),
            'group' => 'general',
            'value' => fake()->word(),
            'type' => 'string',
            'label' => fake()->sentence(3),
            'is_public' => false,
        ];
    }
}
