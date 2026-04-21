<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\VacancyState;
use App\Models\Company;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vacancy>
 */
class VacancyFactory extends Factory
{
    protected $model = Vacancy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->jobTitle();

        return [
            'company_id' => Company::factory(),
            'code' => 'HUM-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->randomNumber(5),
            'description' => fake()->paragraphs(3, true),
            'responsibilities' => fake()->paragraph(),
            'requirements' => fake()->paragraph(),
            'benefits' => fake()->paragraph(),
            'vacancies_count' => 1,
            'is_remote' => fake()->boolean(),
            'is_hybrid' => false,
            'salary_is_public' => false,
            'state' => VacancyState::Borrador,
            'priority' => Priority::Normal,
        ];
    }
}
