<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PsychometricTest;
use App\Models\PsychometricTestSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PsychometricTestSection>
 */
class PsychometricTestSectionFactory extends Factory
{
    protected $model = PsychometricTestSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'-'.fake()->word();

        return [
            'psychometric_test_id' => PsychometricTest::factory(),
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'sort_order' => 0,
        ];
    }
}
