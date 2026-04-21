<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'legal_name' => $name.' S.A. de C.V.',
            'trade_name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(5),
            'description' => fake()->paragraph(),
            'website' => fake()->url(),
            'founded_year' => fake()->numberBetween(1990, 2024),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'status' => 'active',
            'is_verified' => false,
        ];
    }
}
